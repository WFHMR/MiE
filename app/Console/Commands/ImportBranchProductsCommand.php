<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LocksInventoryImport;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnitQuantity;
use App\Models\Tax;
use App\Models\TaxGroup;
use App\Models\Unit;
use App\Models\UnitGroup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Import branch stock from the 14-column "รายการสินค้า-สาขา" CSV export.
 *
 * Fixes the defect in ClearAndImportProductsSeeder where the $branchId argument was
 * accepted but never written to nexopos_products_unit_quantities, which left every
 * one of the 15,481 existing rows with branch_id = NULL and therefore visible from
 * every branch at once.
 *
 * Column layout (0-indexed):
 *   0 รหัสสต็อก        1 รหัสบาร์โค๊ดสินค้า   2 ชื่อ/รายละเอียดสินค้า
 *   3 ปริมาณ/ขนาด      4 หน่วย (ของขนาด)      5 บรรจุ (อัตราแปลง)
 *   6 หน่วยขาย         7 กลุ่มหลัก            8 ย่อย
 *   9 ผู้จำหน่าย        10 ชื่อ/บริษัท          11 ราคาทุน
 *   12 ขาย (VAT)       13 จำนวน
 *
 * Prices are quoted PER SALE UNIT. This was proven against the master catalogue export
 * by locating 9 barcodes present twice -- once bulk, once single -- where
 * cost_bulk / บรรจุ equalled cost_single in all 9 cases and none contradicted it.
 */
class ImportBranchProductsCommand extends Command
{
    use LocksInventoryImport;

    protected $signature = 'rgbf:import-branch-products
        {file : Absolute path to the CSV export}
        {--branch= : Target branch id written to nexopos_products_unit_quantities.branch_id}
        {--author=1 : User id recorded as author on created records}
        {--dry-run : Parse and report without writing anything}
        {--purge-orphans : Delete unit quantity rows whose product no longer exists}';

    protected $description = 'Import branch stock from a 14-column branch CSV export, writing branch_id correctly.';

    /**
     * The shop quotes every price with VAT already included.
     */
    private const VAT_RATE = 7.0;

    private const VAT_GROUP_NAME = 'VAT 7%';

    private ?int $vatGroupId = null;

    /** @var array<string, array{unit_id:int, group_id:int}> */
    private array $unitCache = [];

    /** @var array<string, int> */
    private array $categoryCache = [];

    public function handle(): int
    {
        $file = (string) $this->argument( 'file' );
        $branchId = $this->option( 'branch' );
        $authorId = (int) $this->option( 'author' );
        $dryRun = (bool) $this->option( 'dry-run' );

        if ( ! is_readable( $file ) ) {
            $this->error( "ไม่พบไฟล์หรืออ่านไม่ได้: {$file}" );

            return self::FAILURE;
        }

        if ( $branchId === null || $branchId === '' ) {
            $this->error( 'ต้องระบุ --branch=<id> เพื่อไม่ให้เกิดข้อมูล branch_id = NULL ซ้ำรอยเดิม' );

            return self::FAILURE;
        }

        $branchId = (int) $branchId;
        $branch = DB::table( 'nexopos_branches' )->where( 'id', $branchId )->first();

        if ( $branch === null ) {
            $this->error( "ไม่พบสาขา id={$branchId}" );

            return self::FAILURE;
        }

        $this->info( 'สาขาเป้าหมาย : ' . $branch->name . " (id={$branchId})" );
        $this->info( 'ไฟล์          : ' . basename( $file ) );
        $this->info( 'โหมด          : ' . ( $dryRun ? 'ทดลอง (ไม่เขียนข้อมูล)' : 'เขียนข้อมูลจริง' ) );
        $this->newLine();

        $rows = $this->parse( $file );

        if ( $rows === [] ) {
            $this->error( 'ไม่พบแถวข้อมูลที่ใช้ได้' );

            return self::FAILURE;
        }

        [ $unique, $duplicateRows ] = $this->deduplicate( $rows );

        $this->line( 'แถวข้อมูลที่อ่านได้   : ' . count( $rows ) );
        $this->line( 'บาร์โค้ดไม่ซ้ำ        : ' . count( $unique ) );
        $this->line( 'แถวซ้ำที่ตัดออก      : ' . $duplicateRows );

        $noPrice = count( array_filter( $unique, static fn ( array $r ): bool => $r['sale'] <= 0 ) );

        if ( $noPrice > 0 ) {
            $this->warn( "ราคาขาย = 0 จำนวน {$noPrice} รายการ — นำเข้าแล้วแต่ขายไม่ได้จนกำหนดราคา" );
        }

        $this->newLine();

        if ( $dryRun ) {
            $this->table(
                [ 'บาร์โค้ด', 'ชื่อ', 'หน่วยขาย', 'บรรจุ', 'ทุน', 'ขาย', 'จำนวน' ],
                array_map(
                    static fn ( array $r ): array => [
                        $r['barcode'],
                        mb_substr( $r['name'], 0, 34 ),
                        $r['saleUnit'],
                        $r['pack'],
                        number_format( $r['cost'], 2 ),
                        number_format( $r['sale'], 2 ),
                        $r['qty'],
                    ],
                    array_slice( array_values( $unique ), 0, 15 )
                )
            );

            $this->info( 'ทดลองเสร็จ ไม่มีการเขียนข้อมูล' );

            return self::SUCCESS;
        }

        if ( (bool) $this->option( 'purge-orphans' ) ) {
            $this->purgeOrphans();
        }

        $this->ensureVatGroup( $authorId );

        $created = 0;
        $updated = 0;
        $failed = 0;
        $bar = $this->output->createProgressBar( count( $unique ) );

        $bar->start();

        foreach ( $unique as $row ) {
            try {
                DB::transaction( function () use ( $row, $branchId, $authorId, &$created, &$updated ): void {
                    $unit = $this->resolveUnit( $row['saleUnit'], $authorId );
                    $category = $this->resolveCategory( $row['cat'], $authorId );

                    $product = Product::where( 'barcode', $row['barcode'] )->first();
                    $isNew = ! $product instanceof Product;

                    if ( $isNew ) {
                        $product = new Product;
                        $product->barcode = $row['barcode'];
                    }

                    /**
                     * The earlier import wrote the stock code into the SKU, and that code
                     * repeats across the file: 1,785 products shared 17 values. A SKU that
                     * does not identify one product is useless for lookup and misleading in
                     * the till, so the barcode is used instead.
                     */
                    $product->sku = $row['barcode'];

                    $product->name = mb_substr( $row['name'], 0, 190 );
                    $product->description = $this->buildDescription( $row );
                    $product->category_id = $category;
                    $product->unit_group = $unit['group_id'];
                    $product->barcode_type = $this->barcodeType( $row['barcode'] );
                    $product->product_type = 'product';
                    $product->type = Product::TYPE_MATERIALIZED;
                    $product->stock_management = Product::STOCK_MANAGEMENT_ENABLED;
                    $product->accurate_tracking = false;
                    $product->auto_cogs = true;
                    $product->searchable = true;
                    $product->parent_id = 0;
                    $product->on_expiration = Product::EXPIRES_PREVENT_SALES;
                    $product->expires = false;
                    $product->tax_type = 'inclusive';

                    /**
                     * Without a tax group the till shows VAT as zero on every line, because
                     * the amount is derived from the group attached to the product rather
                     * than from a global rate. The shop's prices already include VAT, so
                     * the type stays inclusive and the tax is extracted downward.
                     */
                    $product->tax_group_id = $this->vatGroupId;

                    /**
                     * A product priced at zero must not reach the till or it would be
                     * handed over for free. Six rows in the branch export have no sale
                     * price, so they are imported but withheld from sale.
                     */
                    $product->status = $row['sale'] > 0
                        ? Product::STATUS_AVAILABLE
                        : Product::STATUS_UNAVAILABLE;

                    $product->author_id = $authorId;
                    $product->save();

                    $this->saveUnitQuantity( $product, $row, $unit['unit_id'], $branchId );

                    $isNew ? $created++ : $updated++;
                } );
            } catch ( \Throwable $e ) {
                $failed++;
                $this->newLine();
                $this->error( 'บาร์โค้ด ' . $row['barcode'] . ' : ' . $e->getMessage() );

                if ( $failed > 25 ) {
                    $bar->finish();
                    $this->newLine( 2 );
                    $this->error( 'ผิดพลาดเกิน 25 รายการ หยุดการนำเข้า' );

                    return self::FAILURE;
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine( 2 );

        $this->info( "สร้างใหม่   : {$created}" );
        $this->info( "อัปเดต      : {$updated}" );

        if ( $failed > 0 ) {
            $this->error( "ผิดพลาด     : {$failed}" );
        }

        $this->newLine();
        $this->verify( $branchId );

        return self::SUCCESS;
    }

    /**
     * @return list<array{line:int, stock:string, barcode:string, name:string, size:string, sizeUnit:string, pack:string, saleUnit:string, cat:string, subcat:string, provCode:string, provName:string, cost:float, sale:float, qty:float}>
     */
    private function parse( string $file ): array
    {
        $handle = fopen( $file, 'r' );

        if ( $handle === false ) {
            return [];
        }

        $rows = [];
        $lineNo = 0;

        while ( ( $c = fgetcsv( $handle ) ) !== false ) {
            $lineNo++;

            $stock = trim( (string) ( $c[0] ?? '' ) );
            $name = trim( (string) ( $c[2] ?? '' ) );

            if ( $stock === 'รหัสสต็อก' || str_contains( $stock, 'รายการสินค้า' ) || str_contains( $stock, 'รหัสกลุ่มหลัก' ) ) {
                continue;
            }

            if ( $name === '' || str_starts_with( $name, '--' ) ) {
                continue;
            }

            $barcode = trim( (string) ( $c[1] ?? '' ) );

            if ( $barcode === '' || $barcode === '0' ) {
                continue;
            }

            $rows[] = [
                'line' => $lineNo,
                'stock' => $stock,
                'barcode' => $barcode,
                'name' => $name,
                'size' => trim( (string) ( $c[3] ?? '' ) ),
                'sizeUnit' => trim( (string) ( $c[4] ?? '' ) ),
                'pack' => trim( (string) ( $c[5] ?? '' ) ),
                'saleUnit' => trim( (string) ( $c[6] ?? '' ) ),
                'cat' => trim( (string) ( $c[7] ?? '' ) ),
                'subcat' => trim( (string) ( $c[8] ?? '' ) ),
                'provCode' => trim( (string) ( $c[9] ?? '' ) ),
                'provName' => trim( (string) ( $c[10] ?? '' ) ),
                'cost' => $this->toFloat( (string) ( $c[11] ?? '0' ) ),
                'sale' => $this->toFloat( (string) ( $c[12] ?? '0' ) ),
                'qty' => $this->toFloat( (string) ( $c[13] ?? '0' ) ),
            ];
        }

        fclose( $handle );

        return $rows;
    }

    /**
     * Keep the first occurrence of each barcode.
     *
     * Summing quantities would be wrong: the export repeats a product verbatim, quantity
     * included, so barcode 28850282111019 appearing 3 times at quantity 1 means 1 in
     * stock, not 3.
     *
     * @param  list<array<string, mixed>>                            $rows
     * @return array{0: array<string, array<string, mixed>>, 1: int}
     */
    private function deduplicate( array $rows ): array
    {
        $unique = [];
        $dropped = 0;

        foreach ( $rows as $row ) {
            $barcode = (string) $row['barcode'];

            if ( isset( $unique[ $barcode ] ) ) {
                $dropped++;

                // Prefer a row that carries a sale price over one that does not.
                if ( $unique[ $barcode ]['sale'] <= 0 && $row['sale'] > 0 ) {
                    $unique[ $barcode ] = $row;
                }

                continue;
            }

            $unique[ $barcode ] = $row;
        }

        return [ $unique, $dropped ];
    }

    private function toFloat( string $value ): float
    {
        return (float) str_replace( [ ',', ' ', "\u{00A0}" ], '', trim( $value ) );
    }

    /**
     * EAN-13 is only claimed for values that actually are 13 digits. Everything else,
     * including the internal codes such as "9093" and "C1-75", is treated as Code 128
     * which accepts variable length and alphanumerics.
     */
    private function barcodeType( string $barcode ): string
    {
        return preg_match( '/^\d{13}$/', $barcode ) === 1 ? 'ean13' : 'code128';
    }

    private function buildDescription( array $row ): ?string
    {
        $parts = [];

        if ( $row['size'] !== '' ) {
            $parts[] = 'ขนาด ' . $row['size'] . ' ' . $row['sizeUnit'];
        }

        if ( $row['pack'] !== '' && $row['pack'] !== '1' ) {
            $parts[] = 'บรรจุ ' . $row['pack'] . ' ต่อ' . $row['saleUnit'];
        }

        if ( $row['provName'] !== '' ) {
            $parts[] = 'ผู้จำหน่าย ' . $row['provName'];
        }

        if ( $row['stock'] !== '' ) {
            $parts[] = 'รหัสสต็อก ' . $row['stock'];
        }

        return $parts === [] ? null : implode( ' | ', $parts );
    }

    /**
     * Write the branch stock row. The branch_id is part of the lookup key so each branch
     * keeps its own quantity and price for the same product and unit.
     *
     * Prices in the export already include VAT, so the net is derived downward rather
     * than added on top.
     *
     * @param array<string, mixed> $row
     */
    private function saveUnitQuantity( Product $product, array $row, int $unitId, int $branchId ): void
    {
        $unitQuantity = ProductUnitQuantity::where( 'product_id', $product->id )
            ->where( 'unit_id', $unitId )
            ->where( 'branch_id', $branchId )
            ->first();

        if ( ! $unitQuantity instanceof ProductUnitQuantity ) {
            $unitQuantity = new ProductUnitQuantity;
            $unitQuantity->product_id = $product->id;
            $unitQuantity->unit_id = $unitId;
            $unitQuantity->branch_id = $branchId;
        }

        $price = (float) $row['sale'];
        $cost = (float) $row['cost'];
        $net = round( $price / ( 1 + ( self::VAT_RATE / 100 ) ), 5 );
        $tax = round( $price - $net, 5 );

        $unitQuantity->type = 'product';
        $unitQuantity->barcode = $row['barcode'];
        $unitQuantity->quantity = $row['qty'];
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
     * @return array{unit_id:int, group_id:int}
     */
    private function resolveUnit( string $name, int $authorId ): array
    {
        $name = trim( $name ) !== '' ? trim( $name ) : 'ชิ้น';

        if ( isset( $this->unitCache[ $name ] ) ) {
            return $this->unitCache[ $name ];
        }

        $unit = Unit::where( 'name', $name )->first();

        if ( $unit === null ) {
            $group = UnitGroup::firstOrCreate(
                [ 'name' => $name ],
                [ 'author_id' => $authorId ]
            );

            $identifier = Str::slug( Str::ascii( $name ) );

            if ( $identifier === '' ) {
                $identifier = 'u-' . substr( md5( $name ), 0, 12 );
            }

            $unit = Unit::create( [
                'name' => $name,
                'identifier' => $identifier,
                'author_id' => $authorId,
                'value' => 1,
                'base_unit' => true,
                'group_id' => $group->id,
            ] );
        }

        return $this->unitCache[ $name ] = [
            'unit_id' => (int) $unit->id,
            'group_id' => (int) $unit->group_id,
        ];
    }

    private function resolveCategory( string $code, int $authorId ): int
    {
        $name = trim( $code ) !== '' ? 'กลุ่ม ' . trim( $code ) : 'ไม่ระบุหมวด';

        if ( isset( $this->categoryCache[ $name ] ) ) {
            return $this->categoryCache[ $name ];
        }

        $category = ProductCategory::firstOrCreate(
            [ 'name' => $name ],
            [ 'author_id' => $authorId ]
        );

        return $this->categoryCache[ $name ] = (int) $category->id;
    }

    /**
     * Makes sure a VAT group exists and actually contains a rate.
     *
     * The database held two tax groups and an empty nexopos_taxes table, so every group was
     * a label with no rate inside it. A product pointed at such a group still reports zero
     * VAT, which is why the till footer showed 0.00 on a 7 percent inclusive catalogue.
     */
    private function ensureVatGroup( int $authorId ): void
    {
        $group = TaxGroup::where( 'name', self::VAT_GROUP_NAME )->first();

        if ( ! $group instanceof TaxGroup ) {
            $group = new TaxGroup;
            $group->name = self::VAT_GROUP_NAME;
            $group->description = 'ภาษีมูลค่าเพิ่มตามประมวลรัษฎากร อัตราปัจจุบันร้อยละ 7';
            $group->author_id = $authorId;
            $group->save();

            $this->info( 'สร้างกลุ่มภาษี ' . self::VAT_GROUP_NAME . ' (id=' . $group->id . ')' );
        }

        $this->vatGroupId = (int) $group->id;

        $tax = Tax::where( 'tax_group_id', $group->id )->first();

        if ( ! $tax instanceof Tax ) {
            $tax = new Tax;
            $tax->name = 'ภาษีมูลค่าเพิ่ม 7%';
            $tax->description = 'อัตราร้อยละ 7 ตามพระราชกฤษฎีกาที่ต่ออายุเป็นรายปี';
            $tax->rate = self::VAT_RATE;
            $tax->tax_group_id = $group->id;
            $tax->author_id = $authorId;
            $tax->save();

            $this->warn( 'กลุ่มภาษีไม่มีอัตราภาษีอยู่ข้างใน — สร้างอัตรา 7% ให้แล้ว (id=' . $tax->id . ')' );
        }

        $this->info( 'กลุ่มภาษีที่จะผูกกับสินค้า: ' . $group->name . ' (id=' . $this->vatGroupId . ') อัตรา ' . $tax->rate . '%' );
        $this->newLine();
    }

    /**
     * Remove unit quantity rows pointing at products that no longer exist. These are
     * left over from an earlier import that deleted products without their stock rows.
     */
    private function purgeOrphans(): void
    {
        $count = DB::table( 'nexopos_products_unit_quantities as uq' )
            ->leftJoin( 'nexopos_products as p', 'p.id', '=', 'uq.product_id' )
            ->whereNull( 'p.id' )
            ->count();

        if ( $count === 0 ) {
            $this->info( 'ไม่พบแถวสต็อกกำพร้า' );

            return;
        }

        $this->warn( "พบแถวสต็อกกำพร้า {$count} แถว (สินค้าที่อ้างถึงไม่มีอยู่แล้ว) กำลังลบ" );

        $deleted = DB::table( 'nexopos_products_unit_quantities' )
            ->whereNotIn( 'product_id', DB::table( 'nexopos_products' )->select( 'id' ) )
            ->delete();

        $this->info( "ลบแล้ว {$deleted} แถว" );
        $this->newLine();
    }

    private function verify( int $branchId ): void
    {
        $rows = DB::table( 'nexopos_products_unit_quantities' )
            ->selectRaw( 'COALESCE(branch_id, -1) AS b, COUNT(*) AS c, COUNT(DISTINCT product_id) AS p' )
            ->groupBy( 'b' )
            ->orderBy( 'b' )
            ->get();

        $this->info( 'สต็อกแยกตามสาขาหลังนำเข้า' );

        $this->table(
            [ 'branch_id', 'แถว', 'สินค้า' ],
            $rows->map( static fn ( $r ): array => [
                $r->b === -1 ? 'NULL' : $r->b,
                $r->c,
                $r->p,
            ] )->all()
        );

        $sellable = DB::table( 'nexopos_products as p' )
            ->join( 'nexopos_products_unit_quantities as uq', 'uq.product_id', '=', 'p.id' )
            ->where( 'uq.branch_id', $branchId )
            ->where( 'uq.sale_price', '>', 0 )
            ->distinct()
            ->count( 'p.id' );

        $this->info( "สินค้าที่ขายได้ในสาขา {$branchId} (มีราคา > 0) : {$sellable}" );
    }
}
