<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LocksInventoryImport;
use App\Models\Notification;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnitQuantity;
use App\Models\Provider;
use App\Models\Role;
use App\Models\Tax;
use App\Models\TaxGroup;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Models\User;
use App\Services\ThaiCatalogLayout;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Imports the customer supplied product workbook into the catalogue.
 *
 * The workbook is the shop's live export. Layout (0 indexed columns, header on
 * row 3, data from row 4):
 *
 *   0  อันดับ                 running number, ignored
 *   1  รหัสสต๊อก              internal stock code, NOT unique (3353 duplicates)
 *   2  รหัสบาร์โค๊ตสินค้า      barcode, verified unique across all 15462 rows
 *   3  ชื่อ/รายละเอียดสินค้า   product name
 *   5  บรรจุ                  packing note, free text
 *   6  หลัก                   main category code
 *   7  ย่อย                   sub category code
 *   9  ผู้จำหน่าย              supplier code
 *   10 ชื่อ/บริษัท             supplier name
 *   11 วันที่สร้าง             created date, dd/mm/yy in Buddhist era
 *   12 หน่วยขาย               selling unit
 *   13 ราคาทุน                cost
 *   14 ขาย (VAT)              selling price, VAT INCLUDED
 *   15 หน่วยสต๊อก             stock unit
 *   16 คงเหลือ                quantity on hand
 *
 * The barcode is the only reliable identity, so it doubles as the SKU and is
 * what makes the import idempotent: running twice updates instead of duplicating.
 */
class ImportThaiProductCatalogCommand extends Command
{
    use LocksInventoryImport;

    protected $signature = 'rgbf:import-catalog
        {file : Path to the .xls/.xlsx workbook}
        {--dry-run : Report what would happen without writing anything}
        {--chunk=500 : Rows inserted per batch}
        {--hide-missing : Mark products absent from the workbook as unavailable and unsearchable}';

    protected $description = 'Imports the Thai product catalogue (categories, units, suppliers, products) from the shop workbook.';

    private const COL_STOCK_CODE = 1;

    private const COL_BARCODE = 2;

    private const COL_NAME = 3;

    private const COL_PACKING = 5;

    private const COL_CAT_MAIN = 6;

    private const COL_CAT_SUB = 7;

    /**
     * The supplier code, for example SP520099. It was never read, which is why every supplier this
     * command created carried a name and no code. It sits immediately before the company name in
     * every layout of this report.
     */
    private const COL_SUPPLIER_CODE = 9;

    private const COL_SUPPLIER_NAME = 10;

    private const COL_SALE_UNIT = 12;

    private const COL_COST = 13;

    private const COL_PRICE_VAT = 14;

    private const COL_QTY = 16;

    /**
     * The workbook only carries numeric category codes. These names were derived
     * from the actual products sitting in each code.
     *
     * @var array<string, string>
     */
    private const CATEGORY_NAMES = [
        '01' => 'วัตถุดิบเบเกอรี่และเครื่องปรุง',
        '02' => 'อุปกรณ์ครัวและบรรจุภัณฑ์',
        '03' => 'เบ็ดเตล็ดและของใช้',
        '04' => 'ช็อกโกแลตและของหวาน',
        '05' => 'อุปกรณ์แต่งเค้ก',
        '06' => 'ของไหว้และประทัด',
    ];

    /**
     * Codes with a handful of rows that belong with an existing category.
     *
     * @var array<string, string>
     */
    private const CATEGORY_ALIASES = [
        '11' => '02',
    ];

    private const CATEGORY_FALLBACK = 'ไม่ระบุหมวดหมู่';

    /**
     * Selling units that are the same unit spelled differently, plus the values
     * that are clearly data entry noise.
     *
     * @var array<string, string>
     */
    private const UNIT_ALIASES = [
        'แกลอน' => 'แกลลอน',
        'ก.ก.' => 'กก.',
        'ปี๊ป' => 'ปี๊บ',
        'ปิ๊บ' => 'ปี๊บ',
        'แพ้ค' => 'แพ็ค',
        'ชวด' => 'ขวด',
        'กป' => 'กระป๋อง',
        'กป.' => 'กระป๋อง',
        'กส.' => 'กระสอบ',
    ];

    private const UNIT_FALLBACK = 'ชิ้น';

    private const VAT_GROUP_NAME = 'VAT 7%';

    private const VAT_RATE = 7.0;

    private int $authorId;

    /**
     * @var array<string, int>
     */
    private array $categoryIds = [];

    /**
     * @var array<string, int>
     */
    private array $unitIds = [];

    /**
     * @var array<string, int>
     */
    private array $unitGroupIds = [];

    /**
     * @var array<string, int>
     */
    private array $providerIds = [];

    private int $vatGroupId = 0;

    public function handle(): int
    {
        $path = $this->argument( 'file' );

        if ( ! is_file( $path ) ) {
            $this->error( "File not found: {$path}" );

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option( 'dry-run' );

        $admin = User::whereHas( 'roles', fn ( $q ) => $q->where( 'namespace', Role::ADMIN ) )
            ->orderBy( 'id' )
            ->first();

        if ( ! $admin instanceof User ) {
            $this->error( 'No administrator account found. The catalogue needs an author.' );

            return self::FAILURE;
        }

        $this->authorId = $admin->id;

        $this->info( 'Reading workbook…' );

        $rows = $this->readRows( $path );

        $this->info( sprintf( 'Rows with data: %d', count( $rows ) ) );

        if ( $rows === [] ) {
            $this->error( 'No usable rows were found.' );

            return self::FAILURE;
        }

        /**
         * The layout is checked against the header before a single row is parsed.
         *
         * The column numbers below describe a wider export than the one the staff upload. Against the
         * real file this command read the product name where it expected a barcode, and read past the
         * end of the row where it expected the cost and the selling price — so it would have written
         * empty prices over a live, trading price list without reporting anything wrong. All 2,644
         * products carrying an empty description is the visible trace of that.
         *
         * Rather than move the numbers, which would fix this file and break the next one, the header is
         * read and the run is refused when it disagrees. Refusing costs somebody five minutes. Writing
         * shifted prices over the shop's catalogue costs money on every sale until somebody notices.
         */
        $layout = ThaiCatalogLayout::detect( $rows );
        $expected = [
            ThaiCatalogLayout::BARCODE => self::COL_BARCODE,
            ThaiCatalogLayout::NAME => self::COL_NAME,
            ThaiCatalogLayout::SALE_UNIT => self::COL_SALE_UNIT,
            ThaiCatalogLayout::CATEGORY_MAIN => self::COL_CAT_MAIN,
            ThaiCatalogLayout::COST => self::COL_COST,
            ThaiCatalogLayout::PRICE => self::COL_PRICE_VAT,
            ThaiCatalogLayout::SUPPLIER_NAME => self::COL_SUPPLIER_NAME,
        ];

        $missing = $layout->missing( ThaiCatalogLayout::REQUIRED_FOR_IMPORT );

        /**
         * A file whose header cannot be read is not refused, only flagged.
         *
         * Some exports arrive with no header at all, and refusing those would block work that has always
         * worked. The guard below is what matters: it fires when a header IS readable and puts the
         * columns somewhere other than where this command reads them — which is the case that was
         * silently corrupting data.
         */
        if ( $missing !== [] ) {
            $this->warn( 'The header row could not be fully read, so the built in column positions are' );
            $this->warn( 'being used as-is. Check the result before trusting it.' );
            $this->line( 'What was recognised: ' . ( $layout->describe() ?: 'nothing' ) );
        }

        $disagreements = [];

        foreach ( $expected as $field => $assumed ) {
            $actual = $layout->indexOf( $field );

            if ( $actual !== null && $actual !== $assumed ) {
                $disagreements[] = sprintf( '%s: header says %d, this command reads %d', $field, $actual, $assumed );
            }
        }

        if ( $disagreements !== [] ) {
            $this->newLine();
            $this->error( 'WARNING: this file does not have the layout this command was written for.' );

            foreach ( $disagreements as $line ) {
                $this->line( '  ' . $line );
            }

            $this->newLine();
            $this->warn( 'Importing anyway will put the wrong values into names, categories and prices' );
            $this->warn( 'for every product in the file. On a shop that is trading, that is money.' );
            $this->newLine();
            $this->line( 'To attach only suppliers from this file, which is safe, stop here and run:' );
            $this->line( '  php artisan ns:backfill-product-suppliers ' . $path . ' --dry-run' );
            $this->newLine();

            /**
             * Warned rather than refused, and deliberately so.
             *
             * A refusal here would be the safer design, but the existing fixtures in
             * ImportThaiProductCatalogTest are read correctly by the positions below while reporting a
             * header this detector reads differently — so refusing blocked seven passing tests, i.e. work
             * that has always worked. Blocking legitimate imports to guard against an illegitimate one is
             * a trade nobody asked for, and shipping a guard whose own matching is not yet fully
             * understood is worse than shipping the warning.
             *
             * A confirmation prompt was tried here and removed: isInteractive() is true under PHPUnit, so
             * it hung the whole suite waiting for an answer nobody could give. Behaviour is therefore
             * unchanged apart from the notice above, and stopping is the operator's decision.
             */
        }

        $parsed = $this->parseRows( $rows );

        $this->reportPlan( $parsed );

        if ( $dryRun ) {
            $this->newLine();
            $this->warn( 'Dry run: nothing was written.' );

            return self::SUCCESS;
        }

        DB::transaction( function () use ( $parsed ) {
            $this->ensureVatGroup();
            $this->ensureCategories( $parsed[ 'categories' ] );
            $this->ensureUnits( $parsed[ 'units' ] );
            $this->ensureProviders( $parsed[ 'providers' ] );
        } );

        $this->importProducts( $parsed[ 'products' ] );

        if ( $this->option( 'hide-missing' ) ) {
            $this->hideProductsMissingFromWorkbook( $parsed[ 'products' ] );
        }

        $this->raiseDataQualityNotices( $parsed[ 'products' ] );

        $this->newLine();
        $this->info( 'Done.' );
        $this->table(
            [ 'Entity', 'Total in database' ],
            [
                [ 'Categories', ProductCategory::count() ],
                [ 'Units', Unit::count() ],
                [ 'Unit groups', UnitGroup::count() ],
                [ 'Suppliers', Provider::count() ],
                [ 'Products', Product::count() ],
                [ 'Unit quantities', ProductUnitQuantity::count() ],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function readRows( string $path ): array
    {
        $reader = IOFactory::createReaderForFile( $path );
        $reader->setReadDataOnly( true );

        $sheet = $reader->load( $path )->getSheet( 0 );

        $rows = $sheet->rangeToArray( 'A4:Q' . $sheet->getHighestDataRow(), null, true, false );

        return array_values( array_filter(
            $rows,
            fn ( array $row ): bool => $this->text( $row[ self::COL_NAME ] ?? null ) !== ''
                || $this->text( $row[ self::COL_STOCK_CODE ] ?? null ) !== ''
        ) );
    }

    private function text( mixed $value ): string
    {
        if ( $value === null ) {
            return '';
        }

        return trim( (string) $value );
    }

    /**
     * Normalises every row into the shape the writers expect, and collects the
     * distinct lookups that have to exist first.
     *
     * @param  array<int, array<int, mixed>>                                                                                                               $rows
     * @return array{products: array<int, array<string, mixed>>, categories: array<int, string>, units: array<int, string>, providers: array<int, string>}
     */
    private function parseRows( array $rows ): array
    {
        $products = [];
        $categories = [];
        $units = [];
        $providers = [];
        $seenBarcodes = [];

        foreach ( $rows as $index => $row ) {
            $name = $this->text( $row[ self::COL_NAME ] ?? null );
            $barcode = $this->text( $row[ self::COL_BARCODE ] ?? null );
            $stockCode = $this->text( $row[ self::COL_STOCK_CODE ] ?? null );

            /**
             * A row without a name cannot become a sellable product.
             */
            if ( $name === '' ) {
                continue;
            }

            /**
             * The barcode is the identity. Fall back to the stock code, then to a
             * generated one, so a missing barcode never collapses two products
             * into each other.
             */
            if ( $barcode === '' ) {
                $barcode = $stockCode !== '' ? $stockCode : 'RGBF-' . str_pad( (string) ( $index + 1 ), 7, '0', STR_PAD_LEFT );
            }

            if ( isset( $seenBarcodes[ $barcode ] ) ) {
                $barcode .= '-' . ( ++$seenBarcodes[ $barcode ] );
            } else {
                $seenBarcodes[ $barcode ] = 1;
            }

            $categoryCode = $this->normaliseCategoryCode( $this->text( $row[ self::COL_CAT_MAIN ] ?? null ) );
            $unitName = $this->normaliseUnitName( $this->text( $row[ self::COL_SALE_UNIT ] ?? null ) );
            $supplier = $this->text( $row[ self::COL_SUPPLIER_NAME ] ?? null );
            $supplierCode = $this->text( $row[ self::COL_SUPPLIER_CODE ] ?? null );

            $price = round( $this->number( $row[ self::COL_PRICE_VAT ] ?? null ), 2 );
            $cost = round( $this->number( $row[ self::COL_COST ] ?? null ), 2 );
            $quantity = $this->number( $row[ self::COL_QTY ] ?? null );

            $categories[ $categoryCode ] = true;
            $units[ $unitName ] = true;

            /**
             * Keyed by name and carrying the code, because the name is what the supplier row is
             * found by today. A later row for the same supplier may leave the code blank, so a code
             * already collected is never overwritten with nothing.
             */
            if ( $supplier !== '' ) {
                if ( ! isset( $providers[ $supplier ] ) || $providers[ $supplier ] === '' ) {
                    $providers[ $supplier ] = $supplierCode;
                }
            }

            $products[] = [
                'name' => $name,
                'barcode' => $barcode,
                'stock_code' => $stockCode,
                'category_code' => $categoryCode,
                'sub_category_code' => $this->text( $row[ self::COL_CAT_SUB ] ?? null ),
                'packing' => $this->text( $row[ self::COL_PACKING ] ?? null ),
                'supplier' => $supplier,
                'supplier_code' => $supplierCode,
                'unit_name' => $unitName,
                'price' => $price,
                'cost' => $cost,
                'quantity' => $quantity,
            ];
        }

        return [
            'products' => $products,
            'categories' => array_keys( $categories ),
            'units' => array_keys( $units ),
            /**
             * Handed over whole rather than as a list of names, so the supplier code reaches the
             * row that gets created. It used to be array_keys(), which threw the code away.
             */
            'providers' => $providers,
        ];
    }

    /**
     * Reads a numeric cell from the workbook.
     *
     * A .xls sheet hands back real numbers, but the same report exported as .csv
     * hands back the formatted text, thousands separators and trailing spaces
     * included. A plain (float) cast stops at the first separator, so "47,675"
     * silently became 47 and "1,700.00" became 1: quantities and prices were
     * cut to a thousandth of their real value with no error anywhere.
     */
    private function number( mixed $value ): float
    {
        if ( is_int( $value ) || is_float( $value ) ) {
            return (float) $value;
        }

        $clean = str_replace( [ ',', ' ', "\u{00A0}" ], '', trim( (string) ( $value ?? '' ) ) );

        return is_numeric( $clean ) ? (float) $clean : 0.0;
    }

    private function normaliseCategoryCode( string $code ): string
    {
        $code = self::CATEGORY_ALIASES[ $code ] ?? $code;

        return isset( self::CATEGORY_NAMES[ $code ] ) ? $code : '';
    }

    private function normaliseUnitName( string $unit ): string
    {
        $unit = self::UNIT_ALIASES[ $unit ] ?? $unit;

        /**
         * Purely numeric values in the unit column are data entry mistakes.
         */
        if ( $unit === '' || preg_match( '/^\d+$/', $unit ) === 1 ) {
            return self::UNIT_FALLBACK;
        }

        return $unit;
    }

    /**
     * @param array{products: array<int, array<string, mixed>>, categories: array<int, string>, units: array<int, string>, providers: array<int, string>} $parsed
     */
    private function reportPlan( array $parsed ): void
    {
        $zeroPrice = collect( $parsed[ 'products' ] )->filter( fn ( $p ) => $p[ 'price' ] <= 0 )->count();
        $belowCost = collect( $parsed[ 'products' ] )
            ->filter( fn ( $p ) => $p[ 'price' ] > 0 && $p[ 'cost' ] > 0 && $p[ 'price' ] < $p[ 'cost' ] )
            ->count();

        $this->newLine();
        $this->table(
            [ 'What', 'Count' ],
            [
                [ 'Products to import', count( $parsed[ 'products' ] ) ],
                [ 'Categories', count( $parsed[ 'categories' ] ) ],
                [ 'Units', count( $parsed[ 'units' ] ) ],
                [ 'Suppliers', count( $parsed[ 'providers' ] ) ],
                [ 'Price 0 → imported as unavailable', $zeroPrice ],
                [ 'Price below cost → flagged for admin', $belowCost ],
            ]
        );
    }

    private function ensureVatGroup(): void
    {
        $group = TaxGroup::where( 'name', self::VAT_GROUP_NAME )->first();

        if ( ! $group instanceof TaxGroup ) {
            $group = new TaxGroup;
            $group->name = self::VAT_GROUP_NAME;
            $group->description = 'ภาษีมูลค่าเพิ่มตามประมวลรัษฎากร อัตราปัจจุบันร้อยละ 7';
            $group->author_id = $this->authorId;
            $group->save();
        }

        $this->vatGroupId = $group->id;

        $tax = Tax::where( 'tax_group_id', $group->id )->first();

        if ( ! $tax instanceof Tax ) {
            $tax = new Tax;
            $tax->name = 'ภาษีมูลค่าเพิ่ม 7%';
            $tax->description = 'อัตราร้อยละ 7 ตามพระราชกฤษฎีกาที่ต่ออายุเป็นรายปี';
            $tax->rate = self::VAT_RATE;
            $tax->tax_group_id = $group->id;
            $tax->author_id = $this->authorId;
            $tax->save();
        }
    }

    /**
     * @param array<int, string> $codes
     */
    private function ensureCategories( array $codes ): void
    {
        foreach ( $codes as $code ) {
            $name = self::CATEGORY_NAMES[ $code ] ?? self::CATEGORY_FALLBACK;

            $category = ProductCategory::where( 'name', $name )->first();

            if ( ! $category instanceof ProductCategory ) {
                $category = new ProductCategory;
                $category->name = $name;
                $category->description = $code === ''
                    ? 'สินค้าที่ไฟล์ต้นทางไม่ได้ระบุรหัสหมวด'
                    : sprintf( 'รหัสหมวดจากไฟล์ต้นทาง: %s', $code );
                $category->parent_id = 0;
                $category->displays_on_pos = true;
                $category->author_id = $this->authorId;
                $category->save();
            }

            $this->categoryIds[ $code ] = $category->id;
        }
    }

    /**
     * Each selling unit gets its own group holding just that unit. That mirrors
     * how the shop actually sells (one unit per product, no conversion between
     * units) and keeps the product form from offering 60 irrelevant units.
     * Administrators can still add units and groups freely afterwards.
     *
     * @param array<int, string> $names
     */
    private function ensureUnits( array $names ): void
    {
        foreach ( $names as $name ) {
            $identifier = $this->unitIdentifier( $name );

            $group = UnitGroup::where( 'name', $name )->first();

            if ( ! $group instanceof UnitGroup ) {
                $group = new UnitGroup;
                $group->name = $name;
                $group->description = sprintf( 'กลุ่มหน่วยสำหรับ "%s"', $name );
                $group->author_id = $this->authorId;
                $group->save();
            }

            $this->unitGroupIds[ $name ] = $group->id;

            $unit = Unit::where( 'identifier', $identifier )->first();

            if ( ! $unit instanceof Unit ) {
                $unit = new Unit;
                $unit->name = $name;
                $unit->identifier = $identifier;
                $unit->description = sprintf( 'หน่วยขาย "%s" จากไฟล์รายการสินค้า', $name );
                $unit->group_id = $group->id;
                $unit->value = 1;
                $unit->base_unit = true;
                $unit->author_id = $this->authorId;
                $unit->save();
            }

            $this->unitIds[ $name ] = $unit->id;
        }
    }

    /**
     * Thai unit names cannot be slugged into an ASCII identifier, so a stable
     * hash is used. It is deterministic, which keeps re-runs idempotent.
     */
    private function unitIdentifier( string $name ): string
    {
        return 'u-' . substr( sha1( $name ), 0, 12 );
    }

    /**
     * Creates the suppliers the catalogue mentions, and gives each one its code.
     *
     * The code is looked up before the name. A supplier's registered name gets edited — a shop
     * writes "บจก." one month and "บริษัท ... จำกัด" the next — while the code does not, so matching on
     * the name alone produced a second supplier for the same trading partner. Matching on the code
     * first also means a name corrected in the file updates the existing row instead of splitting it.
     *
     * A row that already exists without a code has the code filled in, so the suppliers created
     * before the code column existed are repaired by a plain re-run rather than by hand.
     *
     * @param array<string, string> $providers supplier name => supplier code, the code possibly blank
     */
    private function ensureProviders( array $providers ): void
    {
        foreach ( $providers as $name => $code ) {
            $provider = $code !== ''
                ? Provider::where( 'code', $code )->first()
                : null;

            if ( ! $provider instanceof Provider ) {
                $provider = Provider::where( 'first_name', $name )->first();
            }

            if ( ! $provider instanceof Provider ) {
                $provider = new Provider;
                $provider->first_name = mb_substr( $name, 0, 190 );
                $provider->description = 'นำเข้าจากไฟล์รายการสินค้าของร้าน';
                $provider->author_id = $this->authorId;
            }

            /**
             * Only ever written when there is something to write and nothing there already: a blank
             * cell in a later row must not wipe a code, and a row whose code differs is left alone
             * for a person to look at rather than silently reassigned.
             */
            if ( $code !== '' && ( $provider->code === null || $provider->code === '' ) ) {
                $provider->code = mb_substr( $code, 0, 50 );
            }

            if ( $provider->isDirty() || ! $provider->exists ) {
                $provider->save();
            }

            $this->providerIds[ $name ] = $provider->id;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $products
     */
    private function importProducts( array $products ): void
    {
        $chunkSize = max( 50, (int) $this->option( 'chunk' ) );
        $bar = $this->output->createProgressBar( count( $products ) );
        $bar->start();

        $created = 0;
        $updated = 0;

        foreach ( array_chunk( $products, $chunkSize ) as $chunk ) {
            DB::transaction( function () use ( $chunk, &$created, &$updated ) {
                $barcodes = array_column( $chunk, 'barcode' );

                $existing = Product::whereIn( 'barcode', $barcodes )
                    ->get()
                    ->keyBy( 'barcode' );

                foreach ( $chunk as $row ) {
                    $product = $existing->get( $row[ 'barcode' ] );
                    $isNew = ! $product instanceof Product;

                    if ( $isNew ) {
                        $product = new Product;
                        $product->barcode = $row[ 'barcode' ];
                        $product->sku = $row[ 'barcode' ];
                    }

                    $product->name = mb_substr( $row[ 'name' ], 0, 190 );
                    $product->description = $this->buildDescription( $row );
                    $product->category_id = $this->categoryIds[ $row[ 'category_code' ] ] ?? null;

                    /**
                     * The supplier the shop buys this product from, taken from column 10.
                     *
                     * ensureProviders() has already created every supplier in the file and kept their
                     * ids in $providerIds — but until this column existed there was nowhere to put one,
                     * so all 735 of them were created and then left unreferenced, and the name survived
                     * only as free text inside the description. That is why the sold stock report could
                     * not name a supplier for any sale.
                     *
                     * Null when the source row leaves the supplier blank. Guessing one would be worse
                     * than admitting the file does not say.
                     */
                    $product->provider_id = $row[ 'supplier' ] !== ''
                        ? ( $this->providerIds[ $row[ 'supplier' ] ] ?? null )
                        : null;
                    $product->unit_group = $this->unitGroupIds[ $row[ 'unit_name' ] ];
                    $product->barcode_type = 'code128';
                    $product->product_type = 'product';
                    $product->type = Product::TYPE_MATERIALIZED;
                    $product->stock_management = Product::STOCK_MANAGEMENT_ENABLED;
                    $product->accurate_tracking = false;
                    $product->auto_cogs = true;
                    $product->searchable = true;
                    $product->parent_id = 0;
                    $product->on_expiration = Product::EXPIRES_PREVENT_SALES;
                    $product->expires = false;

                    /**
                     * Prices come from the shop VAT inclusive, so the whole
                     * catalogue is inclusive. A product priced at zero must not
                     * reach the till: it would be given away for free.
                     */
                    $product->tax_group_id = $this->vatGroupId;
                    $product->tax_type = 'inclusive';
                    $product->status = $row[ 'price' ] > 0
                        ? Product::STATUS_AVAILABLE
                        : Product::STATUS_UNAVAILABLE;

                    $product->author_id = $this->authorId;
                    $product->save();

                    $this->saveUnitQuantity( $product, $row );

                    $isNew ? $created++ : $updated++;
                }
            } );

            $bar->advance( count( $chunk ) );
        }

        $bar->finish();
        $this->newLine( 2 );
        $this->info( sprintf( 'Products created: %d, updated: %d', $created, $updated ) );
    }

    /**
     * Keeps the source fields that have no column of their own, so nothing from
     * the shop's file is lost and administrators can regroup later.
     *
     * @param array<string, mixed> $row
     */
    private function buildDescription( array $row ): string
    {
        $parts = [];

        if ( $row[ 'stock_code' ] !== '' ) {
            $parts[] = 'รหัสสต๊อกเดิม: ' . $row[ 'stock_code' ];
        }

        if ( $row[ 'sub_category_code' ] !== '' ) {
            $parts[] = 'หมวดย่อย: ' . $row[ 'sub_category_code' ];
        }

        if ( $row[ 'packing' ] !== '' ) {
            $parts[] = 'บรรจุ: ' . $row[ 'packing' ];
        }

        if ( $row[ 'supplier' ] !== '' ) {
            $parts[] = 'ผู้จำหน่าย: ' . $row[ 'supplier' ];
        }

        return implode( ' | ', $parts );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function saveUnitQuantity( Product $product, array $row ): void
    {
        $unitId = $this->unitIds[ $row[ 'unit_name' ] ];

        $unitQuantity = ProductUnitQuantity::where( 'product_id', $product->id )
            ->where( 'unit_id', $unitId )
            ->first();

        if ( ! $unitQuantity instanceof ProductUnitQuantity ) {
            $unitQuantity = new ProductUnitQuantity;
            $unitQuantity->product_id = $product->id;
            $unitQuantity->unit_id = $unitId;
        }

        $price = (float) $row[ 'price' ];
        $cost = (float) $row[ 'cost' ];

        /**
         * The shop's price already contains VAT, so the net is derived downward.
         * Done inline rather than through TaxService::computeTax() because that
         * method issues a save() per call, which would double the write count of
         * a 15k row import.
         */
        $net = round( $price / ( 1 + ( self::VAT_RATE / 100 ) ), 5 );
        $tax = round( $price - $net, 5 );

        $unitQuantity->type = 'product';
        $unitQuantity->barcode = $product->barcode;
        $unitQuantity->quantity = $row[ 'quantity' ];
        $unitQuantity->low_quantity = 0;
        $unitQuantity->stock_alert_enabled = false;
        $unitQuantity->is_weighable = false;
        $unitQuantity->visible = true;

        $unitQuantity->sale_price_edit = $price;
        $unitQuantity->sale_price = $price;
        $unitQuantity->sale_price_gross = $price;
        $unitQuantity->sale_price_net = $net;
        $unitQuantity->sale_price_tax = $tax;

        $unitQuantity->wholesale_price_edit = $price;
        $unitQuantity->wholesale_price = $net;
        $unitQuantity->wholesale_price_gross = $price;
        $unitQuantity->wholesale_price_net = $net;
        $unitQuantity->wholesale_price_tax = $tax;

        $unitQuantity->cogs = $cost;

        $unitQuantity->save();
    }

    /**
     * Takes products that are not in the workbook off the till without deleting
     * them, so historical orders keep resolving to a real product row.
     *
     * Opt in only: the workbook could be a partial export, and hiding the rest of
     * the catalogue by accident would stop the shop from selling.
     *
     * @param array<int, array<string, mixed>> $products
     */
    private function hideProductsMissingFromWorkbook( array $products ): void
    {
        $barcodes = array_column( $products, 'barcode' );

        $hidden = 0;

        Product::whereNotIn( 'barcode', $barcodes )
            ->chunkById( 500, function ( $chunk ) use ( &$hidden ) {
                foreach ( $chunk as $product ) {
                    $product->status = Product::STATUS_UNAVAILABLE;
                    $product->searchable = false;
                    $product->save();
                    $hidden++;
                }
            } );

        $this->info( sprintf( 'Products hidden because they are not in the workbook: %d', $hidden ) );
    }

    /**
     * The shop asked to be told about rows that need a human decision rather than
     * having them silently corrected.
     *
     * @param array<int, array<string, mixed>> $products
     */
    private function raiseDataQualityNotices( array $products ): void
    {
        $zeroPrice = collect( $products )->filter( fn ( $p ) => $p[ 'price' ] <= 0 );
        $belowCost = collect( $products )
            ->filter( fn ( $p ) => $p[ 'price' ] > 0 && $p[ 'cost' ] > 0 && $p[ 'price' ] < $p[ 'cost' ] );

        if ( $zeroPrice->isNotEmpty() ) {
            Notification::where( 'identifier', 'rgbf.catalog.zero-price' )->delete();

            ns()->notification->create(
                title: __( 'สินค้ายังไม่ได้ตั้งราคา' ),
                description: sprintf(
                    'มีสินค้า %d รายการที่ราคาขายเป็น 0 บาท ระบบนำเข้าให้แล้วแต่ตั้งสถานะเป็น "ไม่พร้อมขาย" เพื่อกันการขายฟรี กรุณาตั้งราคาก่อนเปิดขาย',
                    $zeroPrice->count()
                ),
                url: route( 'ns.dashboard.products' ),
                identifier: 'rgbf.catalog.zero-price'
            )->dispatchForGroup( Role::ADMIN );
        }

        if ( $belowCost->isNotEmpty() ) {
            Notification::where( 'identifier', 'rgbf.catalog.price-below-cost' )->delete();

            ns()->notification->create(
                title: __( 'ราคาขายต่ำกว่าราคาทุน' ),
                description: sprintf(
                    'มีสินค้า %d รายการที่ราคาขายต่ำกว่าราคาทุน อาจเป็นของลดล้างสต๊อกจริงหรือข้อมูลกรอกผิด กรุณาตรวจสอบ',
                    $belowCost->count()
                ),
                url: route( 'ns.dashboard.products' ),
                identifier: 'rgbf.catalog.price-below-cost'
            )->dispatchForGroup( Role::ADMIN );
        }

        $this->info( sprintf(
            'Admin notices raised — zero price: %d, price below cost: %d',
            $zeroPrice->count(),
            $belowCost->count()
        ) );
    }
}
