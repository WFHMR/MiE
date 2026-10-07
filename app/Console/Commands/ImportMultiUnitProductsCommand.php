<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LocksInventoryImport;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnitQuantity;
use App\Models\Register;
use App\Models\Tax;
use App\Models\TaxGroup;
use App\Models\Unit;
use App\Models\UnitGroup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds a branch's catalogue so one product carries a barcode and a price for every unit
 * it is sold in, while holding a single stock figure.
 *
 * The shop receives cartons and breaks them open on the shelf. A cashier facing a queue
 * scans whichever label is turned towards them, a can or the carton it came in, and cannot
 * be asked to flip a switch first. Keeping a separate stock figure per unit would let the
 * same carton be sold once whole and again as its contents, so the figure lives on the
 * smallest unit and every larger unit becomes a label that converts into it. The conversion
 * is done by ProductService::resolveStockBearingUnit, which only acts on products carrying
 * the stock_on_base_unit flag this command sets.
 *
 * Two files are needed because neither is sufficient alone. The branch export says what is
 * on the shelf but only at the level the branch happens to stock it, usually the carton. The
 * master registry lists every unit level for the same goods, tied together by รหัสสต็อก,
 * each with its own barcode, its own pack ratio and its own price.
 *
 * Column layout of both files (0-indexed), the branch export carrying one extra column:
 *   0 รหัสสต็อก      1 รหัสบาร์โค๊ดสินค้า  2 ชื่อ/รายละเอียดสินค้า
 *   3 ปริมาณ/ขนาด    4 หน่วย              5 บรรจุ (pack ratio)
 *   6 หน่วยขาย       7 กลุ่มหลัก           8 ย่อย
 *   9 ผู้จำหน่าย      10 ชื่อ/บริษัท        11 ราคาทุน
 *   12 ขาย (VAT)     13 จำนวน (branch export only)
 *
 * จำนวน counts the branch's own unit, not pieces: จำนวน 1 against บรรจุ 48 หีบ means one
 * carton, forty eight cans. Confirmed against a physical shelf count before this was written.
 *
 * Nothing is written for goods whose unit levels cannot be read without guessing. Those are
 * listed so the source file can be corrected, because a wrong ratio silently misprices and
 * misdeducts every future sale of that item.
 */
class ImportMultiUnitProductsCommand extends Command
{
    use LocksInventoryImport;

    protected $signature = 'rgbf:import-multiunit-products
        {branch-file : Path to the branch CSV export (14 columns, carries จำนวน)}
        {registry-file : Path to the master registry CSV (13 columns, every unit level)}
        {--branch= : Branch id written to nexopos_products_unit_quantities.branch_id}
        {--author=1 : User id recorded as author on created records}
        {--dry-run : Report the full plan without writing anything}
        {--limit=0 : Process at most this many groups, for a cautious first pass}
        {--force : Proceed even though the branch is mid-sale}
        {--ratios= : CSV of confirmed pack ratios, columns รหัสสต็อก,บาร์โค้ด,บรรจุ}
        {--singles : Also load the skipped goods as ordinary one unit products}
        {--retire-missing : Hide every product the two files do not mention}';

    protected $description = 'Give a branch one product per goods with a barcode and price per unit, and a single stock figure.';

    private const VAT_RATE = 7.0;

    private const VAT_GROUP_NAME = 'VAT 7%';

    private ?int $vatGroupId = null;

    /** @var array<string, int> */
    private array $unitGroupCache = [];

    /** @var array<string, int> */
    private array $categoryCache = [];

    public function handle(): int
    {
        $branchFile = (string) $this->argument( 'branch-file' );
        $registryFile = (string) $this->argument( 'registry-file' );
        $branchId = $this->option( 'branch' );
        $authorId = (int) $this->option( 'author' );
        $dryRun = (bool) $this->option( 'dry-run' );
        $limit = (int) $this->option( 'limit' );

        foreach ( [ 'ไฟล์สาขา' => $branchFile, 'ไฟล์ทะเบียน' => $registryFile ] as $label => $path ) {
            if ( ! is_readable( $path ) ) {
                $this->error( "{$label} อ่านไม่ได้: {$path}" );

                return self::FAILURE;
            }
        }

        if ( $branchId === null || $branchId === '' ) {
            $this->error( 'ต้องระบุ --branch=<id>' );

            return self::FAILURE;
        }

        $branchId = (int) $branchId;
        $branch = DB::table( 'nexopos_branches' )->where( 'id', $branchId )->first();

        if ( $branch === null ) {
            $this->error( "ไม่พบสาขา id={$branchId}" );

            return self::FAILURE;
        }

        if ( ! $this->assertSchemaReady() ) {
            return self::FAILURE;
        }

        $this->info( 'สาขาเป้าหมาย : ' . $branch->name . " (id={$branchId})" );
        $this->info( 'โหมด          : ' . ( $dryRun ? 'ทดลอง (ไม่เขียนข้อมูล)' : 'เขียนข้อมูลจริง' ) );
        $this->newLine();

        $branchRows = $this->parse( $branchFile, withQuantity: true );
        $registryRows = $this->parse( $registryFile, withQuantity: false );

        if ( $branchRows === [] || $registryRows === [] ) {
            $this->error( 'อ่านข้อมูลจากไฟล์ไม่ได้' );

            return self::FAILURE;
        }

        /**
         * Both files repeat the same wrong ratio, so a confirmed one has to reach both: the
         * registry decides how the units relate, the branch row decides how much of it is on
         * the shelf.
         */
        $overrides = $this->readConfirmedRatios();

        $registryRows = $this->applyConfirmedRatios( $registryRows, $overrides, 'ทะเบียน' );
        $branchRows = $this->applyConfirmedRatios( $branchRows, $overrides, 'สาขา' );

        $plan = $this->buildPlan( $branchRows, $registryRows );

        $this->reportPlan( $plan );

        if ( $plan[ 'groups' ] === [] ) {
            $this->warn( 'ไม่มีกลุ่มที่นำเข้าได้' );
        }

        if ( $dryRun ) {
            $this->info( 'ทดลองเสร็จ ไม่มีการเขียนข้อมูล' );

            return self::SUCCESS;
        }

        if ( ! $this->assertBranchIsIdle( $branchId ) ) {
            return self::FAILURE;
        }

        $this->ensureVatGroup( $authorId );

        $groups = $limit > 0 ? array_slice( $plan[ 'groups' ], 0, $limit ) : $plan[ 'groups' ];

        $result = $groups === [] ? self::SUCCESS : $this->apply( $groups, $branchId, $authorId );

        /**
         * Only meaningful for a complete pass: with --limit most goods are outside the slice by
         * design and resetting them would undo the work of an earlier run.
         */
        if ( $limit === 0 ) {
            $this->reconcileDrifted( $plan, $branchId, $authorId );
        }

        /**
         * The ladder covers the goods the register describes at two or more levels. Everything else
         * the branch stocks still has to be sellable, which is what this loads.
         */
        $singleRows = $this->singleUnitRows( $plan );

        if ( (bool) $this->option( 'singles' ) && $limit === 0 && $singleRows !== [] ) {
            $counts = $this->createSingleUnitProducts( $singleRows, $branchId, $authorId );

            $this->newLine();
            $this->info( sprintf(
                'หน่วยเดียว           : สร้าง %d, แก้ไข %d, พักไว้เพราะราคา 0 %d',
                $counts[ 'created' ],
                $counts[ 'updated' ],
                $counts[ 'withheld' ]
            ) );
        }

        if ( (bool) $this->option( 'retire-missing' ) && $limit === 0 ) {
            $keep = [];

            foreach ( $plan[ 'groups' ] as $group ) {
                foreach ( $group[ 'levels' ] as $level ) {
                    $keep[] = (string) $level[ 'barcode' ];
                }

                foreach ( $group[ 'claims' ] as $claim ) {
                    $keep[] = (string) $claim[ 'barcode' ];
                }
            }

            foreach ( $singleRows as $row ) {
                $keep[] = (string) $row[ 'barcode' ];
            }

            foreach ( $plan[ 'ambiguous' ] as $entry ) {
                foreach ( $entry[ 'claims' ] as $claim ) {
                    $keep[] = (string) $claim[ 'barcode' ];
                }
            }

            $retired = $this->retireProductsNotInPlan( array_values( array_unique( $keep ) ), $branchId );

            $this->newLine();
            $this->info( sprintf(
                'ปลดของเก่าออกจากการขาย : ซ่อน %d รายการ, ล้างสต็อกสาขา %d แถว (ไม่ลบ ประวัติการขายยังอ่านได้)',
                $retired[ 'hidden' ],
                $retired[ 'zeroed' ]
            ) );
        }

        return $result;
    }

    /**
     * The conversion only happens for products carrying the flag, so without the column the
     * import would produce carton labels that deduct one piece each. Better to refuse than
     * to load a catalogue that quietly undercharges.
     */
    private function assertSchemaReady(): bool
    {
        if ( ! DB::getSchemaBuilder()->hasColumn( 'nexopos_products', 'stock_on_base_unit' ) ) {
            $this->error( 'ตาราง nexopos_products ยังไม่มีคอลัมน์ stock_on_base_unit' );
            $this->line( 'รัน php artisan migrate ก่อน แล้วลองอีกครั้ง' );

            return false;
        }

        return true;
    }

    /**
     * Replaces pack ratios the registry states wrongly with ones a person has confirmed.
     *
     * Some goods are unreadable because the file gives two units the same ratio of one, though
     * the name itself says otherwise: น้ำปลาพริก is described as 12ห่อ/กล่อง while both its
     * levels claim one, and มิตรผล น้ำตาลทรายขาว names a 25 กก. sack the same way. The command
     * refuses to infer this, because a wrong ratio quietly misprices and misdeducts every later
     * sale. Supplying it explicitly is how those goods get imported.
     *
     * Overrides are only applied; they are not trusted blindly. Whatever is supplied still has
     * to pass the same checks, so a ratio that does not divide evenly is still refused.
     *
     * @return array<string, float> stock code and barcode joined, to the confirmed ratio
     */
    private function readConfirmedRatios(): array
    {
        $file = (string) ( $this->option( 'ratios' ) ?? '' );

        if ( $file === '' ) {
            return [];
        }

        if ( ! is_readable( $file ) ) {
            $this->warn( "อ่านไฟล์อัตราแปลงไม่ได้ ข้ามไป: {$file}" );

            return [];
        }

        $handle = fopen( $file, 'r' );
        $overrides = [];

        while ( ( $c = fgetcsv( $handle ) ) !== false ) {
            $stock = trim( (string) ( $c[ 0 ] ?? '' ) );
            $barcode = trim( (string) ( $c[ 1 ] ?? '' ) );
            $pack = $this->toFloat( (string) ( $c[ 2 ] ?? '' ) );

            if ( $stock === '' || $stock === 'รหัสสต็อก' || $barcode === '' || $pack <= 0 ) {
                continue;
            }

            /**
             * Keyed on the barcode alone. The two files do not agree on stock codes -- the
             * branch export gives น้ำปลาพริก its own code while the registry files it under
             * another -- but a barcode belongs to exactly one goods in both, which was checked
             * across all 11,428 registry rows before relying on it. The stock code column is
             * kept in the file so a person can see what they are editing.
             */
            $overrides[ $barcode ] = $pack;
        }

        fclose( $handle );

        if ( $overrides === [] ) {
            $this->warn( 'ไฟล์อัตราแปลงไม่มีบรรทัดที่ใช้ได้' );
        }

        return $overrides;
    }

    /**
     * @param  list<array<string, mixed>> $rows
     * @param  array<string, float>       $overrides
     * @return list<array<string, mixed>>
     */
    private function applyConfirmedRatios( array $rows, array $overrides, string $label ): array
    {
        if ( $overrides === [] ) {
            return $rows;
        }

        $applied = 0;

        foreach ( $rows as $index => $row ) {
            $barcode = (string) $row[ 'barcode' ];

            if ( ! isset( $overrides[ $barcode ] ) ) {
                continue;
            }

            if ( abs( (float) $row[ 'pack' ] - $overrides[ $barcode ] ) < 0.0001 ) {
                continue;
            }

            $rows[ $index ][ 'pack' ] = $overrides[ $barcode ];
            $applied++;
        }

        $this->info( "ใช้อัตราแปลงที่ยืนยันแล้วกับไฟล์{$label} : {$applied} บรรทัด" );

        return $rows;
    }

    /**
     * Refuses to rebuild the catalogue underneath a cashier who is in the middle of a sale.
     *
     * A cart already holds reservations against the rows this command is about to replace, so
     * finishing that sale afterwards can charge from a row that no longer means what it did.
     * Closing the tills first costs a minute; getting it wrong at the counter costs a customer.
     */
    private function assertBranchIsIdle( int $branchId ): bool
    {
        $openTills = DB::table( 'nexopos_registers' )
            ->where( 'branch_id', $branchId )
            ->where( 'status', Register::STATUS_OPENED )
            ->pluck( 'name' );

        /**
         * Held carts used to be counted across the whole company, so a cart left open at one branch
         * refused the import for every other branch. Not a hypothetical: two reservation rows
         * survived a completed sale at สาขาท่าช้าง and would have refused a main warehouse import
         * that could not possibly have disturbed them.
         *
         * A reservation carries no branch of its own — the stock row it reserves does — so the branch
         * is read through it, and what counts is a reservation against a row this import will rewrite.
         * Every write below is scoped to --branch: rows are looked up by branch_id, created with it,
         * zeroed by it and retired by it. So a reservation against another branch's row, against a
         * branchless row, or against a row that no longer exists at all is outside everything this
         * command will touch, and refusing on account of it protects nothing while blocking the
         * import — permanently, in the case of the orphans, because nothing ever clears them.
         */
        $heldCarts = DB::table( 'nexopos_orders_storage as carts' )
            ->join(
                'nexopos_products_unit_quantities as reserved',
                'reserved.id',
                '=',
                'carts.unit_quantity_id'
            )
            ->where( 'reserved.branch_id', $branchId )
            ->count();

        if ( $openTills->isEmpty() && $heldCarts === 0 ) {
            return true;
        }

        if ( (bool) $this->option( 'force' ) ) {
            $this->warn( 'สาขายังเปิดขายอยู่ แต่สั่ง --force มา จะดำเนินการต่อ' );

            return true;
        }

        $this->error( 'สาขายังขายอยู่ ยังไม่นำเข้า' );

        if ( $openTills->isNotEmpty() ) {
            $this->line( '  ลิ้นชักที่เปิดอยู่ : ' . $openTills->implode( ', ' ) );
        }

        if ( $heldCarts > 0 ) {
            $this->line( "  ตะกร้าที่ค้างอยู่  : {$heldCarts} รายการ" );
        }

        $this->newLine();
        $this->line( 'ปิดลิ้นชักทุกจุดก่อน แล้วรันอีกครั้ง หรือใส่ --force ถ้ายอมรับความเสี่ยง' );

        return false;
    }

    /**
     * @return list<array{stock:string, barcode:string, name:string, size:string, sizeUnit:string, pack:float, saleUnit:string, cat:string, subcat:string, provName:string, cost:float, sale:float, qty:float}>
     */
    private function parse( string $file, bool $withQuantity ): array
    {
        $handle = fopen( $file, 'r' );

        if ( $handle === false ) {
            return [];
        }

        $rows = [];

        while ( ( $c = fgetcsv( $handle ) ) !== false ) {
            $stock = trim( (string) ( $c[ 0 ] ?? '' ) );
            $name = trim( (string) ( $c[ 2 ] ?? '' ) );

            if ( $stock === 'รหัสสต็อก' || str_contains( $stock, 'รายการสินค้า' ) || str_contains( $stock, 'รายงาน' ) || str_contains( $stock, 'รหัสกลุ่มหลัก' ) ) {
                continue;
            }

            if ( $name === '' || str_starts_with( $name, '--' ) ) {
                continue;
            }

            $barcode = trim( (string) ( $c[ 1 ] ?? '' ) );

            if ( $barcode === '' || $barcode === '0' || $stock === '' ) {
                continue;
            }

            $pack = $this->toFloat( (string) ( $c[ 5 ] ?? '1' ) );

            $rows[] = [
                'stock' => $stock,
                'barcode' => $barcode,
                'name' => $name,
                'size' => trim( (string) ( $c[ 3 ] ?? '' ) ),
                'sizeUnit' => trim( (string) ( $c[ 4 ] ?? '' ) ),
                'pack' => $pack > 0 ? $pack : 1.0,
                'saleUnit' => trim( (string) ( $c[ 6 ] ?? '' ) ) !== '' ? trim( (string) ( $c[ 6 ] ?? '' ) ) : 'ชิ้น',
                'cat' => trim( (string) ( $c[ 7 ] ?? '' ) ),
                'subcat' => trim( (string) ( $c[ 8 ] ?? '' ) ),
                'provName' => trim( (string) ( $c[ 10 ] ?? '' ) ),
                'cost' => $this->toFloat( (string) ( $c[ 11 ] ?? '0' ) ),
                'sale' => $this->toFloat( (string) ( $c[ 12 ] ?? '0' ) ),
                'qty' => $withQuantity ? $this->toFloat( (string) ( $c[ 13 ] ?? '0' ) ) : 0.0,
            ];
        }

        fclose( $handle );

        return $rows;
    }

    /**
     * Works out, per goods, which unit holds the stock, how much of it there is, and what
     * every other unit is worth.
     *
     * @param  list<array<string, mixed>>                                                                                                                                                                                                                                                         $branchRows
     * @param  list<array<string, mixed>>                                                                                                                                                                                                                                                         $registryRows
     * @return array{groups: list<array<string, mixed>>, unmatched: list<array<string, mixed>>, singleLevel: list<array<string, mixed>>, ambiguous: list<array<string, mixed>>, extraBarcodes: list<array{string, string, string}>, corruptBarcodes: list<array{string, string, string, string}>}
     */
    private function buildPlan( array $branchRows, array $registryRows ): array
    {
        /**
         * The export repeats a product verbatim, quantity included, so barcode
         * 8857129458166 appearing six times at quantity five means five in stock and not
         * thirty. The first occurrence wins, except that a row carrying a price is preferred
         * over one that does not.
         */
        $branch = [];

        foreach ( $branchRows as $row ) {
            $barcode = (string) $row[ 'barcode' ];

            if ( ! isset( $branch[ $barcode ] ) ) {
                $branch[ $barcode ] = $row;

                continue;
            }

            if ( $branch[ $barcode ][ 'sale' ] <= 0 && $row[ 'sale' ] > 0 ) {
                $branch[ $barcode ] = $row;
            }
        }

        $registryByBarcode = [];
        $registryByStock = [];

        foreach ( $registryRows as $row ) {
            $registryByBarcode[ $row[ 'barcode' ] ] ??= $row;
            $registryByStock[ $row[ 'stock' ] ][ $row[ 'barcode' ] ] ??= $row;
        }

        /**
         * Several branch rows can point at the same goods: the branch stocks the sack and
         * the loose bag on two lines. They have to become one product with the quantities
         * added together, or the same goods would exist twice and be sold twice.
         */
        $claims = [];

        $unmatched = [];

        foreach ( $branch as $barcode => $row ) {
            if ( ! isset( $registryByBarcode[ $barcode ] ) ) {
                $unmatched[] = $row;

                continue;
            }

            $claims[ $registryByBarcode[ $barcode ][ 'stock' ] ][] = $row;
        }

        $groups = [];
        $singleLevel = [];
        $ambiguous = [];
        $extraBarcodes = [];

        $corruptBarcodes = [];

        foreach ( $claims as $stock => $branchClaims ) {
            $levels = array_values( $registryByStock[ $stock ] );

            /**
             * A spreadsheet turns a long numeric barcode into 2.00E+66 and the real digits are
             * gone for good. Such a level cannot be scanned or corrected here, so it is dropped
             * and listed. Dropping only the level keeps the rest of the goods usable.
             */
            $levels = array_values( array_filter( $levels, function ( array $l ) use ( $stock, &$corruptBarcodes ): bool {
                if ( preg_match( '/^\d+(\.\d+)?E\+\d+$/i', (string) $l[ 'barcode' ] ) !== 1 ) {
                    return true;
                }

                $corruptBarcodes[] = [ $stock, (string) $l[ 'barcode' ], (string) $l[ 'saleUnit' ], (string) $l[ 'name' ] ];

                return false;
            } ) );

            if ( count( $levels ) < 2 ) {
                foreach ( $branchClaims as $claim ) {
                    $singleLevel[] = $claim;
                }

                continue;
            }

            usort( $levels, static fn ( array $a, array $b ): int => $a[ 'pack' ] <=> $b[ 'pack' ] );

            $basePack = (float) $levels[ 0 ][ 'pack' ];

            /**
             * Levels sharing the smallest ratio are only safe when they are the same unit
             * under two barcodes. Two different units both claiming a ratio of one means the
             * file does not say how they relate: มิตรผล น้ำตาลทรายขาว lists ถุง and กระสอบ
             * both at บรรจุ 1 though a sack holds twenty five bags. Importing that would
             * deduct one bag for a whole sack, so it is left alone and reported.
             */
            $atBase = array_values( array_filter(
                $levels,
                static fn ( array $l ): bool => abs( (float) $l[ 'pack' ] - $basePack ) < 0.0001
            ) );

            $baseUnitNames = array_unique( array_column( $atBase, 'saleUnit' ) );

            if ( count( $baseUnitNames ) > 1 ) {
                $ambiguous[] = [
                    'stock' => $stock,
                    'name' => $levels[ 0 ][ 'name' ],
                    'reason' => 'หน่วยฐานกำกวม: ' . implode( ' / ', array_map(
                        fn ( array $l ): string => $l[ 'saleUnit' ] . '(บรรจุ ' . $this->trimNumber( (float) $l[ 'pack' ] ) . ')',
                        $atBase
                    ) ),
                    'claims' => $branchClaims,
                ];

                continue;
            }

            /**
             * Ratios have to land on whole numbers of the base unit. กล่องใส E-66 lists แพ็ค at
             * บรรจุ 100 meaning sheets per pack, and หีบ at 30 meaning packs per case, so
             * dividing them gives 3.333 packs to a case. Nothing can be sold in a third of a
             * pack, and a figure like that would drift the stock count on every sale.
             */
            $fractional = array_values( array_filter(
                $levels,
                static function ( array $l ) use ( $basePack ): bool {
                    $value = (float) $l[ 'pack' ] / $basePack;

                    return abs( $value - round( $value ) ) > 0.0001;
                }
            ) );

            if ( $fractional !== [] ) {
                $ambiguous[] = [
                    'stock' => $stock,
                    'name' => $levels[ 0 ][ 'name' ],
                    'reason' => 'อัตราแปลงไม่ลงตัว: ' . implode( ' / ', array_map(
                        fn ( array $l ): string => $l[ 'saleUnit' ] . '(บรรจุ ' . $this->trimNumber( (float) $l[ 'pack' ] ) . ')',
                        $levels
                    ) ),
                    'claims' => $branchClaims,
                ];

                continue;
            }

            /**
             * One unit, several barcodes. NexoPOS holds one row per unit, so exactly one of
             * them becomes the row and the rest are reported: they need the barcode aliasing
             * that comes later, not a guess now.
             *
             * Which one is kept used to be whichever the register happened to list first, and
             * that was wrong twice over. เซสท์ เนยขาว lists ถุง once at ฿0 and again at ฿80,
             * and the ฿0 line came first, so the base level was unpriced and the whole product
             * was withheld though the file prices it. พริงเกิลส์ carries two can barcodes and
             * the branch stocks the second, so the row answered to a code nobody could scan
             * here. chooseLevel() decides on what the files say instead of on their order.
             */
            $claimedBarcodes = array_map(
                static fn ( array $claim ): string => (string) $claim[ 'barcode' ],
                $branchClaims
            );

            $byUnit = [];

            foreach ( $levels as $level ) {
                $unitKey = $level[ 'saleUnit' ] . '|' . $this->trimNumber( (float) $level[ 'pack' ] );
                $byUnit[ $unitKey ][] = $level;
            }

            $keptLevels = [];

            foreach ( $byUnit as $candidates ) {
                $chosen = $this->chooseLevel( $candidates, $claimedBarcodes );

                foreach ( $candidates as $index => $candidate ) {
                    if ( $index === $chosen ) {
                        $keptLevels[] = $candidate;

                        continue;
                    }

                    $extraBarcodes[] = [ $stock, (string) $candidate[ 'barcode' ], $candidate[ 'saleUnit' ] ];
                }
            }

            /**
             * Stock is stated in each branch row's own unit, so each is converted into base
             * units before being added: two bags plus one carton of forty eight is fifty
             * bags, not three of something.
             */
            $baseQuantity = 0.0;

            foreach ( $branchClaims as $claim ) {
                $baseQuantity += (float) $claim[ 'qty' ] * ( (float) $claim[ 'pack' ] / $basePack );
            }

            /**
             * The product keeps the barcode of an existing record where possible so this is
             * an update rather than a second copy of the same goods.
             */
            $canonical = $branchClaims[ 0 ];

            foreach ( $branchClaims as $claim ) {
                if ( abs( (float) $claim[ 'pack' ] - $basePack ) < 0.0001 ) {
                    $canonical = $claim;

                    break;
                }
            }

            $groups[] = [
                'stock' => $stock,
                'name' => $canonical[ 'name' ],
                'canonical' => $canonical,
                'claims' => $branchClaims,
                'basePack' => $basePack,
                'baseQuantity' => $baseQuantity,
                'levels' => array_map(
                    fn ( array $l, int $i ): array => [
                        'barcode' => (string) $l[ 'barcode' ],
                        'unit' => (string) $l[ 'saleUnit' ],
                        /**
                         * Rounded so the number written onto the unit and the number looked up
                         * on a later run are identical. The column keeps five decimals, and an
                         * unrounded float fails to match its own stored form.
                         */
                        'value' => round( (float) $l[ 'pack' ] / $basePack, 5 ),
                        'cost' => (float) $l[ 'cost' ],
                        'sale' => (float) $l[ 'sale' ],
                        'isBase' => $i === 0,
                    ],
                    $keptLevels,
                    array_keys( $keptLevels )
                ),
            ];
        }

        return compact( 'groups', 'unmatched', 'singleLevel', 'ambiguous', 'extraBarcodes', 'corruptBarcodes' );
    }

    /**
     * Of several register lines describing the same unit at the same ratio, the one whose
     * barcode becomes that unit's row.
     *
     * Neither test invents anything. The first asks which of the codes the branch itself
     * records against the goods on its shelf, because that is the code printed on the package
     * this shop holds and therefore the one a scan will present. The second falls back to a
     * line that carries a price, because a base level at ฿0 withholds the whole product while
     * a priced sibling for the same unit sits unused — the price is the file's own, not a
     * guess. Order in the file decides nothing on its own; it only breaks a remaining tie.
     *
     * @param  list<array<string, mixed>> $candidates      same unit, same ratio
     * @param  list<string>               $claimedBarcodes barcodes the branch file lists for these goods
     * @return int                        index into $candidates
     */
    private function chooseLevel( array $candidates, array $claimedBarcodes ): int
    {
        if ( count( $candidates ) === 1 ) {
            return 0;
        }

        foreach ( $candidates as $index => $candidate ) {
            if ( in_array( (string) $candidate[ 'barcode' ], $claimedBarcodes, true ) ) {
                return $index;
            }
        }

        foreach ( $candidates as $index => $candidate ) {
            if ( (float) $candidate[ 'sale' ] > 0 ) {
                return $index;
            }
        }

        return 0;
    }

    /**
     * @param array{groups: list<array<string, mixed>>, unmatched: list<array<string, mixed>>, singleLevel: list<array<string, mixed>>, ambiguous: list<array<string, mixed>>, extraBarcodes: list<array{string, string, string}>, corruptBarcodes: list<array{string, string, string, string}>} $plan
     */
    private function reportPlan( array $plan ): void
    {
        $levelCount = array_sum( array_map( static fn ( array $g ): int => count( $g[ 'levels' ] ), $plan[ 'groups' ] ) );
        $merged = array_values( array_filter( $plan[ 'groups' ], static fn ( array $g ): bool => count( $g[ 'claims' ] ) > 1 ) );

        $signatures = [];

        foreach ( $plan[ 'groups' ] as $group ) {
            $signatures[ $this->signature( $group[ 'levels' ] ) ] = true;
        }

        $this->info( 'สรุปแผนการนำเข้า' );
        $this->table(
            [ 'รายการ', 'จำนวน' ],
            [
                [ 'สินค้าที่จะมีหลายหน่วย', count( $plan[ 'groups' ] ) ],
                [ 'แถวราคา/บาร์โค้ดที่จะสร้าง', $levelCount ],
                [ 'กลุ่มหน่วยที่ต้องสร้าง', count( $signatures ) ],
                [ 'สินค้าที่รวมจากหลายบรรทัด', count( $merged ) ],
                [ 'ข้าม: ไม่พบในทะเบียน', count( $plan[ 'unmatched' ] ) ],
                [ 'ข้าม: ทะเบียนมีระดับเดียว', count( $plan[ 'singleLevel' ] ) ],
                [ 'ข้าม: หน่วยฐานกำกวม', count( $plan[ 'ambiguous' ] ) ],
                [ 'บาร์โค้ดสำรองที่ยังวางไม่ได้', count( $plan[ 'extraBarcodes' ] ) ],
                [ 'บาร์โค้ดเสียในไฟล์ต้นทาง', count( $plan[ 'corruptBarcodes' ] ) ],
            ]
        );

        if ( $plan[ 'corruptBarcodes' ] !== [] ) {
            $this->newLine();
            $this->warn( 'บาร์โค้ดที่โปรแกรมตารางแปลงเป็น scientific notation — เลขจริงหายไป ต้องกรอกใหม่ในไฟล์' );
            $this->table(
                [ 'รหัสสต็อก', 'ค่าที่พบ', 'หน่วย', 'ชื่อ' ],
                array_map(
                    static fn ( array $c ): array => [ $c[ 0 ], $c[ 1 ], $c[ 2 ], mb_substr( $c[ 3 ], 0, 40 ) ],
                    $plan[ 'corruptBarcodes' ]
                )
            );
        }

        if ( $plan[ 'ambiguous' ] !== [] ) {
            $this->newLine();
            $this->warn( 'ข้ามเพราะไฟล์ต้นทางไม่บอกอัตราแปลง — ต้องแก้ไฟล์แล้วนำเข้าอีกครั้ง' );
            $this->table(
                [ 'รหัสสต็อก', 'ชื่อ', 'เหตุผล' ],
                array_map(
                    static fn ( array $a ): array => [
                        $a[ 'stock' ],
                        mb_substr( $a[ 'name' ], 0, 40 ),
                        $a[ 'reason' ],
                    ],
                    $plan[ 'ambiguous' ]
                )
            );
        }

        if ( $merged !== [] ) {
            $this->newLine();
            $this->info( 'สินค้าที่สาขาบันทึกหลายบรรทัด จะรวมเป็นตัวเดียวและบวกสต็อก' );
            $this->table(
                [ 'ชื่อ', 'บรรทัดสาขา', 'สต็อกฐานรวม', 'หน่วยฐาน' ],
                array_map(
                    fn ( array $g ): array => [
                        mb_substr( $g[ 'name' ], 0, 34 ),
                        implode( ' + ', array_map(
                            fn ( array $c ): string => $this->trimNumber( (float) $c[ 'qty' ] ) . '×' . $this->trimNumber( (float) $c[ 'pack' ] ),
                            $g[ 'claims' ]
                        ) ),
                        $this->trimNumber( (float) $g[ 'baseQuantity' ] ),
                        $g[ 'levels' ][ 0 ][ 'unit' ],
                    ],
                    $merged
                )
            );
        }

        $this->newLine();
        $this->info( 'ตัวอย่างสินค้าที่จะสร้าง 8 รายการแรก' );

        foreach ( array_slice( $plan[ 'groups' ], 0, 8 ) as $group ) {
            $this->line( '  ' . mb_substr( $group[ 'name' ], 0, 56 ) );

            foreach ( $group[ 'levels' ] as $level ) {
                $this->line( sprintf(
                    '      %-15s %-10s ×%-6s ราคา %10s %s',
                    $level[ 'barcode' ],
                    $level[ 'unit' ],
                    $this->trimNumber( $level[ 'value' ] ),
                    number_format( $level[ 'sale' ], 2 ),
                    $level[ 'isBase' ] ? '<- ถือสต็อก ' . $this->trimNumber( (float) $group[ 'baseQuantity' ] ) : ''
                ) );
            }
        }

        $this->newLine();
    }

    /**
     * @param list<array<string, mixed>> $groups
     */
    private function apply( array $groups, int $branchId, int $authorId ): int
    {
        $created = 0;
        $updated = 0;
        $rows = 0;
        $emptied = 0;
        $retired = 0;
        $unpriced = 0;
        $failed = 0;

        $bar = $this->output->createProgressBar( count( $groups ) );
        $bar->start();

        foreach ( $groups as $group ) {
            try {
                DB::transaction( function () use ( $group, $branchId, $authorId, &$created, &$updated, &$rows, &$emptied, &$retired, &$unpriced ): void {
                    $unitGroupId = $this->resolveUnitGroup( $group[ 'levels' ], $authorId );
                    $units = $this->resolveUnits( $unitGroupId, $group[ 'levels' ], $authorId );

                    $product = $this->saveProduct( $group, $unitGroupId, $authorId, $created, $updated );

                    /**
                     * A label the registry never priced would reach the till at zero and hand
                     * the goods over for nothing, so it is not offered at all. No row means no
                     * barcode, and a scan of it says the item is unknown, which sends the
                     * cashier to ask rather than to give away a carton.
                     *
                     * The base level is exempt: it decides whether the goods can be sold at
                     * all, and an unpriced one already makes the product unavailable.
                     */
                    $sellable = array_values( array_filter(
                        $group[ 'levels' ],
                        static fn ( array $l ): bool => $l[ 'isBase' ] || (float) $l[ 'sale' ] > 0
                    ) );

                    $unpriced += count( $group[ 'levels' ] ) - count( $sellable );

                    $keepUnitIds = array_map(
                        fn ( array $l ): int => $units[ $this->unitKey( $l ) ],
                        $sellable
                    );

                    $emptied += $this->clearObsoleteRows( $product, $keepUnitIds, $branchId );

                    foreach ( $sellable as $level ) {
                        $this->saveUnitQuantity(
                            product: $product,
                            level: $level,
                            unitId: $units[ $this->unitKey( $level ) ],
                            branchId: $branchId,
                            quantity: $level[ 'isBase' ] ? (float) $group[ 'baseQuantity' ] : 0.0
                        );

                        $rows++;
                    }

                    [ $retiredProducts, $emptiedByRetiring ] = $this->retireDuplicateProducts( $group, $product, $branchId );

                    $retired += $retiredProducts;
                    $emptied += $emptiedByRetiring;
                } );
            } catch ( \Throwable $e ) {
                $failed++;
                $this->newLine();
                $this->error( 'รหัสสต็อก ' . $group[ 'stock' ] . ' : ' . $e->getMessage() );

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

        $this->info( "สินค้าสร้างใหม่        : {$created}" );
        $this->info( "สินค้าอัปเดต           : {$updated}" );
        $this->info( "แถวราคา/บาร์โค้ด       : {$rows}" );

        if ( $retired > 0 ) {
            $this->info( "สินค้าซ้ำที่พักการขาย  : {$retired}" );
        }

        if ( $emptied > 0 ) {
            $this->info( "แถวเก่าที่มีบิลอ้างถึง   : {$emptied} (ล้างสต็อกและบาร์โค้ด เก็บไว้ให้บิลเดิม)" );
        }

        if ( $unpriced > 0 ) {
            $this->warn( "หน่วยที่ทะเบียนไม่ใส่ราคา : {$unpriced} — ไม่สร้างแถวให้ ยิงแล้วจะขึ้นว่าไม่พบ ไม่แจกฟรี" );
        }

        if ( $failed > 0 ) {
            $this->error( "ผิดพลาด               : {$failed}" );
        }

        $this->newLine();
        $this->verify( $branchId );

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $group
     */
    private function saveProduct( array $group, int $unitGroupId, int $authorId, int &$created, int &$updated ): Product
    {
        $canonical = $group[ 'canonical' ];
        $baseLevel = $group[ 'levels' ][ 0 ];

        $product = Product::where( 'barcode', $canonical[ 'barcode' ] )->first();

        if ( ! $product instanceof Product ) {
            foreach ( $group[ 'claims' ] as $claim ) {
                $product = Product::where( 'barcode', $claim[ 'barcode' ] )->first();

                if ( $product instanceof Product ) {
                    break;
                }
            }
        }

        $isNew = ! $product instanceof Product;

        if ( $isNew ) {
            $product = new Product;
            $product->barcode = $canonical[ 'barcode' ];
        }

        $product->sku = $product->barcode;
        $product->name = mb_substr( $canonical[ 'name' ], 0, 190 );
        $product->description = $this->buildDescription( $group );
        $product->category_id = $this->resolveCategory( (string) $canonical[ 'cat' ], $authorId );
        $product->unit_group = $unitGroupId;
        $product->barcode_type = $this->barcodeType( (string) $product->barcode );
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
        $product->tax_group_id = $this->vatGroupId;

        /**
         * This is the flag the deduction reads. Without it every unit would keep its own
         * figure and the carton labels created below would each deduct a single piece.
         */
        $product->stock_on_base_unit = true;

        /**
         * A product priced at zero would be handed over free, so it is loaded but withheld
         * until someone prices it.
         */
        $product->status = $baseLevel[ 'sale' ] > 0
            ? Product::STATUS_AVAILABLE
            : Product::STATUS_UNAVAILABLE;

        $product->author_id = $authorId;
        $product->save();

        $isNew ? $created++ : $updated++;

        return $product;
    }

    /**
     * Puts back the goods this command used to manage but no longer does.
     *
     * A correction to the reading rules can move goods out of the plan: กล่องใส E-66 was
     * accepted while its ratios were taken at face value and is now refused because they do not
     * divide. Its product still carries the flag and the units the earlier pass built, so the
     * till would keep converting by a ratio the command has since judged unusable.
     *
     * Anything left in that position is returned to one unit, one barcode and one price taken
     * straight from the branch file, which is the state it would have been in had this command
     * never touched it.
     */
    private function reconcileDrifted( array $plan, int $branchId, int $authorId ): void
    {
        $managed = [];

        foreach ( $plan[ 'groups' ] as $group ) {
            foreach ( $group[ 'claims' ] as $claim ) {
                $managed[ (string) $claim[ 'barcode' ] ] = true;
            }
        }

        $skipped = [ ...$plan[ 'unmatched' ], ...$plan[ 'singleLevel' ] ];

        foreach ( $plan[ 'ambiguous' ] as $entry ) {
            foreach ( $entry[ 'claims' ] as $claim ) {
                $skipped[] = $claim;
            }
        }

        $reset = 0;

        foreach ( $skipped as $row ) {
            if ( isset( $managed[ (string) $row[ 'barcode' ] ] ) ) {
                continue;
            }

            $product = Product::where( 'barcode', $row[ 'barcode' ] )->first();

            if ( ! $product instanceof Product || (bool) $product->stock_on_base_unit !== true ) {
                continue;
            }

            DB::transaction( function () use ( $product, $row, $branchId, $authorId, &$reset ): void {
                $unit = $this->resolveSingleUnit( (string) $row[ 'saleUnit' ], $authorId );

                $product->stock_on_base_unit = false;
                $product->unit_group = $unit[ 'group_id' ];
                $product->status = (float) $row[ 'sale' ] > 0
                    ? Product::STATUS_AVAILABLE
                    : Product::STATUS_UNAVAILABLE;
                $product->save();

                $this->clearObsoleteRows( $product, [ $unit[ 'unit_id' ] ], $branchId );

                $this->saveUnitQuantity(
                    product: $product,
                    level: [
                        'barcode' => (string) $row[ 'barcode' ],
                        'unit' => (string) $row[ 'saleUnit' ],
                        'value' => 1.0,
                        'cost' => (float) $row[ 'cost' ],
                        'sale' => (float) $row[ 'sale' ],
                        'isBase' => true,
                    ],
                    unitId: $unit[ 'unit_id' ],
                    branchId: $branchId,
                    quantity: (float) $row[ 'qty' ]
                );

                $reset++;
            } );
        }

        if ( $reset > 0 ) {
            $this->newLine();
            $this->warn( "คืนสภาพหน่วยเดียว     : {$reset} รายการ (เคยนำเข้าไว้ แต่กฎใหม่ตัดสินว่าอ่านไม่ได้)" );
        }
    }

    /**
     * The branch rows that will not become a unit ladder and therefore need loading on their own.
     *
     * The ambiguous group is deliberately excluded: its ratios are the thing in doubt, and loading
     * a carton as a single unit would sell a whole carton for the price of one piece.
     *
     * @param  array<string, mixed>       $plan
     * @return list<array<string, mixed>>
     */
    private function singleUnitRows( array $plan ): array
    {
        return [ ...$plan[ 'unmatched' ], ...$plan[ 'singleLevel' ] ];
    }

    /**
     * Loads the goods the branch stocks that cannot become a unit ladder, as ordinary one unit
     * products taken straight from the branch row.
     *
     * Two things land here. The register may hold a single level for the goods, so there is no
     * second unit to convert to. Or the branch scans a barcode the register does not list at all,
     * in which case the branch row is the only description that exists. Either way the branch has
     * the goods on the shelf and the till has to be able to sell them, and reconcileDrifted only
     * repairs products that already exist rather than creating the missing ones.
     *
     * The row's own หน่วยขาย becomes the single unit and its จำนวน the stock, so a line reading
     * "1 หีบ" is stocked as one หีบ, which is what the branch counted.
     *
     * @param  list<array<string, mixed>>                       $rows
     * @return array{created: int, updated: int, withheld: int}
     */
    private function createSingleUnitProducts( array $rows, int $branchId, int $authorId ): array
    {
        $created = 0;
        $updated = 0;
        $withheld = 0;

        /**
         * The same barcode can appear in both skipped lists, and the branch file itself repeats
         * barcodes on separate lines. Quantities are added so nothing is counted twice.
         */
        $byBarcode = [];

        foreach ( $rows as $row ) {
            $barcode = (string) $row[ 'barcode' ];

            if ( $barcode === '' ) {
                continue;
            }

            if ( isset( $byBarcode[ $barcode ] ) ) {
                $byBarcode[ $barcode ][ 'qty' ] = (float) $byBarcode[ $barcode ][ 'qty' ] + (float) $row[ 'qty' ];

                continue;
            }

            $byBarcode[ $barcode ] = $row;
        }

        foreach ( $byBarcode as $barcode => $row ) {
            DB::transaction( function () use ( $row, $barcode, $branchId, $authorId, &$created, &$updated, &$withheld ): void {
                $unit = $this->resolveSingleUnit( (string) $row[ 'saleUnit' ], $authorId );

                $product = Product::where( 'barcode', $barcode )->first();
                $isNew = ! $product instanceof Product;

                if ( $isNew ) {
                    $product = new Product;
                    $product->barcode = $barcode;
                }

                $product->sku = $barcode;
                $product->name = mb_substr( (string) $row[ 'name' ], 0, 190 );
                $product->category_id = $this->resolveCategory( (string) $row[ 'cat' ], $authorId );
                $product->unit_group = $unit[ 'group_id' ];
                $product->barcode_type = $this->barcodeType( $barcode );
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
                $product->tax_group_id = $this->vatGroupId;

                /**
                 * One unit means nothing to convert, so the conversion must stay off. Leaving it
                 * on would send the deduction looking for a base unit that does not exist.
                 */
                $product->stock_on_base_unit = false;

                /**
                 * Priced at zero the till would hand it over free, so it is loaded and withheld.
                 */
                $product->status = (float) $row[ 'sale' ] > 0
                    ? Product::STATUS_AVAILABLE
                    : Product::STATUS_UNAVAILABLE;

                if ( (float) $row[ 'sale' ] <= 0 ) {
                    $withheld++;
                }

                $product->author_id = $authorId;
                $product->save();

                $this->clearObsoleteRows( $product, [ $unit[ 'unit_id' ] ], $branchId );

                $this->saveUnitQuantity(
                    product: $product,
                    level: [
                        'barcode' => $barcode,
                        'unit' => (string) $row[ 'saleUnit' ],
                        'value' => 1.0,
                        'cost' => (float) $row[ 'cost' ],
                        'sale' => (float) $row[ 'sale' ],
                        'isBase' => true,
                    ],
                    unitId: $unit[ 'unit_id' ],
                    branchId: $branchId,
                    quantity: (float) $row[ 'qty' ]
                );

                $isNew ? $created++ : $updated++;
            } );
        }

        return compact( 'created', 'updated', 'withheld' );
    }

    /**
     * Takes every product the two files do not mention out of the till without deleting it.
     *
     * The catalogue carries goods from earlier loads that the branch no longer lists. Deleting
     * them outright would be the tidier result, but sales history points at them and three tills
     * are selling, so a row a cart is holding must not disappear underneath it. Marking them
     * unavailable and unsearchable removes them from the grid, the search and the scanner while
     * leaving every receipt able to render. They can be deleted later from a quiet shop.
     *
     * @param  list<string>                    $keepBarcodes
     * @return array{hidden: int, zeroed: int}
     */
    private function retireProductsNotInPlan( array $keepBarcodes, int $branchId ): array
    {
        $keep = array_fill_keys( array_map( 'strval', $keepBarcodes ), true );

        $hidden = 0;
        $zeroed = 0;

        Product::select( 'id', 'barcode', 'status', 'searchable' )
            ->orderBy( 'id' )
            ->chunk( 500, function ( $products ) use ( $keep, $branchId, &$hidden, &$zeroed ): void {
                foreach ( $products as $product ) {
                    if ( isset( $keep[ (string) $product->barcode ] ) ) {
                        continue;
                    }

                    /**
                     * A unit row of this product may itself carry one of the barcodes being kept,
                     * which happens when the goods are now loaded under a different canonical
                     * barcode. Retiring the parent would take that scan away too.
                     */
                    $keepsAUnit = ProductUnitQuantity::where( 'product_id', $product->id )
                        ->whereIn( 'barcode', array_keys( $keep ) )
                        ->exists();

                    if ( $keepsAUnit ) {
                        continue;
                    }

                    if ( $product->status !== Product::STATUS_UNAVAILABLE || (bool) $product->searchable !== false ) {
                        Product::where( 'id', $product->id )->update( [
                            'status' => Product::STATUS_UNAVAILABLE,
                            'searchable' => false,
                        ] );

                        $hidden++;
                    }

                    $zeroed += ProductUnitQuantity::where( 'product_id', $product->id )
                        ->where( 'branch_id', $branchId )
                        ->where( 'quantity', '!=', 0 )
                        ->update( [ 'quantity' => 0 ] );
                }
            } );

        return compact( 'hidden', 'zeroed' );
    }

    /**
     * A unit that stands alone, matching how the single unit importer stores them: one unit of
     * value one in a group named after it.
     *
     * @return array{unit_id: int, group_id: int}
     */
    private function resolveSingleUnit( string $name, int $authorId ): array
    {
        $name = trim( $name ) !== '' ? trim( $name ) : 'ชิ้น';

        $unit = Unit::where( 'name', $name )
            ->where( 'value', 1 )
            ->where( 'base_unit', true )
            ->whereHas( 'group', fn ( $query ) => $query->where( 'name', $name ) )
            ->first();

        if ( $unit instanceof Unit ) {
            return [ 'unit_id' => (int) $unit->id, 'group_id' => (int) $unit->group_id ];
        }

        $group = UnitGroup::firstOrCreate( [ 'name' => $name ], [ 'author_id' => $authorId ] );

        $unit = Unit::where( 'group_id', $group->id )->where( 'name', $name )->first();

        if ( ! $unit instanceof Unit ) {
            $unit = new Unit;
            $unit->name = $name;
            $unit->identifier = 'su-' . $group->id . '-' . substr( md5( $name ), 0, 10 );
            $unit->group_id = $group->id;
            $unit->value = 1;
            $unit->base_unit = true;
            $unit->author_id = $authorId;
            $unit->save();
        }

        return [ 'unit_id' => (int) $unit->id, 'group_id' => (int) $group->id ];
    }

    /**
     * Clears the stock rows left over from when the product had a single unit.
     *
     * Those rows belong to units that are no longer in the product's group. Left as they are
     * they would be a second stock figure for the same goods and a second row answering to
     * the same barcode, which is the double counting this whole change exists to stop.
     *
     * A row a receipt points at cannot simply go: refunding or reprinting that sale reads it.
     * Those are emptied instead, and their barcode is cleared so a scan can only reach the new
     * row. The receipt keeps its own record of what was charged, so nothing is lost from it.
     *
     * @param list<int> $keepUnitIds
     */
    private function clearObsoleteRows( Product $product, array $keepUnitIds, int $branchId ): int
    {
        $obsolete = ProductUnitQuantity::where( 'product_id', $product->id )
            ->where( 'branch_id', $branchId )
            ->whereNotIn( 'unit_id', $keepUnitIds )
            ->get();

        $emptied = 0;

        foreach ( $obsolete as $row ) {
            $onAReceipt = DB::table( 'nexopos_orders_products' )
                ->where( 'unit_quantity_id', $row->id )
                ->exists();

            if ( ! $onAReceipt ) {
                $row->delete();

                continue;
            }

            $row->barcode = null;
            $row->quantity = 0;
            $row->visible = false;
            $row->save();

            $emptied++;
        }

        return $emptied;
    }

    /**
     * When the branch listed the same goods on several lines, earlier imports made a product
     * per line. Only one survives as the goods; the others give up their stock rows and stop
     * being offered. They are kept rather than deleted so the move is reversible, and because
     * onSale() already excludes them the barcode now resolves to the surviving product.
     *
     * @param  array<string, mixed>  $group
     * @return array{0: int, 1: int} products retired, rows emptied because a receipt needs them
     */
    private function retireDuplicateProducts( array $group, Product $product, int $branchId ): array
    {
        $retired = 0;
        $emptied = 0;

        foreach ( $group[ 'claims' ] as $claim ) {
            if ( (string) $claim[ 'barcode' ] === (string) $product->barcode ) {
                continue;
            }

            $duplicate = Product::where( 'barcode', $claim[ 'barcode' ] )->first();

            if ( ! $duplicate instanceof Product || (int) $duplicate->id === (int) $product->id ) {
                continue;
            }

            /**
             * Passing no units to keep clears every row this duplicate held, while still
             * sparing any row a receipt points at.
             */
            $emptied += $this->clearObsoleteRows( $duplicate, [ 0 ], $branchId );

            $duplicate->status = Product::STATUS_UNAVAILABLE;
            $duplicate->searchable = false;

            /**
             * The flag goes with the stock rows it governed. Leaving it on a product that has
             * none would convert quantities against a unit group it no longer sells through, if
             * anyone ever put it back on sale.
             */
            $duplicate->stock_on_base_unit = false;
            $duplicate->save();

            $retired++;
        }

        return [ $retired, $emptied ];
    }

    /**
     * One unit group per distinct shape, shared by every goods with that shape, so the
     * catalogue ends up with a few dozen groups rather than one per product.
     *
     * @param list<array<string, mixed>> $levels
     */
    private function resolveUnitGroup( array $levels, int $authorId ): int
    {
        $signature = $this->signature( $levels );

        if ( isset( $this->unitGroupCache[ $signature ] ) ) {
            return $this->unitGroupCache[ $signature ];
        }

        $name = mb_substr( implode( ' / ', array_map(
            fn ( array $l ): string => $l[ 'unit' ] . ( $l[ 'isBase' ] ? '' : ' ×' . $this->trimNumber( $l[ 'value' ] ) ),
            $levels
        ) ), 0, 180 );

        $group = UnitGroup::where( 'name', $name )->first();

        if ( ! $group instanceof UnitGroup ) {
            $group = new UnitGroup;
            $group->name = $name;
            $group->description = 'สร้างจากทะเบียนสินค้า อัตราแปลง ' . $signature;
            $group->author_id = $authorId;
            $group->save();
        }

        return $this->unitGroupCache[ $signature ] = (int) $group->id;
    }

    /**
     * @param  list<array<string, mixed>> $levels
     * @return array<string, int>         unit key => unit id
     */
    private function resolveUnits( int $unitGroupId, array $levels, int $authorId ): array
    {
        $resolved = [];

        foreach ( $levels as $level ) {
            $key = $this->unitKey( $level );

            $unit = Unit::where( 'group_id', $unitGroupId )
                ->where( 'name', $level[ 'unit' ] )
                ->where( 'value', $level[ 'value' ] )
                ->first();

            if ( ! $unit instanceof Unit ) {
                $unit = new Unit;
                $unit->name = $level[ 'unit' ];

                /**
                 * The identifier has to be unique and Thai text slugs to nothing, so it is
                 * derived from the group and the ratio instead of the name.
                 */
                $unit->identifier = 'mu-' . $unitGroupId . '-' . substr( md5( $key ), 0, 10 );
                $unit->group_id = $unitGroupId;
                $unit->value = $level[ 'value' ];
                $unit->base_unit = (bool) $level[ 'isBase' ];
                $unit->author_id = $authorId;
                $unit->save();
            }

            $resolved[ $key ] = (int) $unit->id;
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $level
     */
    private function saveUnitQuantity( Product $product, array $level, int $unitId, int $branchId, float $quantity ): void
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

        $price = (float) $level[ 'sale' ];
        $cost = (float) $level[ 'cost' ];

        /**
         * The shop quotes every price with VAT already inside, so the net is taken out of the
         * price rather than added on top.
         */
        $net = round( $price / ( 1 + ( self::VAT_RATE / 100 ) ), 5 );
        $tax = round( $price - $net, 5 );

        $unitQuantity->type = 'product';
        $unitQuantity->barcode = $level[ 'barcode' ];
        $unitQuantity->quantity = $quantity;
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
     * A product pointed at a group with no rate inside it reports zero VAT, which is how the
     * till came to print 0.00 on an inclusive catalogue.
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
        }

        $this->vatGroupId = (int) $group->id;

        $tax = Tax::where( 'tax_group_id', $group->id )->first();

        if ( ! $tax instanceof Tax ) {
            $tax = new Tax;
            $tax->name = 'ภาษีมูลค่าเพิ่ม 7%';
            $tax->description = 'อัตราร้อยละ 7';
            $tax->rate = self::VAT_RATE;
            $tax->tax_group_id = $group->id;
            $tax->author_id = $authorId;
            $tax->save();

            $this->warn( 'กลุ่มภาษีไม่มีอัตราอยู่ข้างใน — สร้างอัตรา 7% ให้แล้ว' );
        }

        $this->info( 'กลุ่มภาษี: ' . $group->name . ' (id=' . $this->vatGroupId . ') อัตรา ' . $tax->rate . '%' );
        $this->newLine();
    }

    /**
     * @param array<string, mixed> $group
     */
    private function buildDescription( array $group ): string
    {
        $canonical = $group[ 'canonical' ];
        $parts = [];

        if ( $canonical[ 'size' ] !== '' ) {
            $parts[] = 'ขนาด ' . $canonical[ 'size' ] . ' ' . $canonical[ 'sizeUnit' ];
        }

        $parts[] = 'หน่วยขาย ' . implode( ', ', array_map(
            fn ( array $l ): string => $l[ 'unit' ] . ' ×' . $this->trimNumber( $l[ 'value' ] ),
            $group[ 'levels' ]
        ) );

        if ( $canonical[ 'provName' ] !== '' ) {
            $parts[] = 'ผู้จำหน่าย ' . $canonical[ 'provName' ];
        }

        $parts[] = 'รหัสสต็อก ' . $group[ 'stock' ];

        return mb_substr( implode( ' | ', $parts ), 0, 480 );
    }

    /**
     * @param list<array<string, mixed>> $levels
     */
    private function signature( array $levels ): string
    {
        return implode( '|', array_map(
            fn ( array $l ): string => $this->trimNumber( (float) $l[ 'value' ] ) . ':' . $l[ 'unit' ],
            $levels
        ) );
    }

    /**
     * @param array<string, mixed> $level
     */
    private function unitKey( array $level ): string
    {
        return $level[ 'unit' ] . '|' . $this->trimNumber( (float) $level[ 'value' ] );
    }

    private function trimNumber( float $value ): string
    {
        return rtrim( rtrim( number_format( $value, 4, '.', '' ), '0' ), '.' );
    }

    private function toFloat( string $value ): float
    {
        return (float) str_replace( [ ',', ' ', "\u{00A0}" ], '', trim( $value ) );
    }

    private function barcodeType( string $barcode ): string
    {
        return preg_match( '/^\d{13}$/', $barcode ) === 1 ? 'ean13' : 'code128';
    }

    private function verify( int $branchId ): void
    {
        $multiUnit = DB::table( 'nexopos_products as p' )
            ->join( 'nexopos_products_unit_quantities as uq', 'uq.product_id', '=', 'p.id' )
            ->where( 'uq.branch_id', $branchId )
            ->where( 'p.stock_on_base_unit', true )
            ->distinct()
            ->count( 'p.id' );

        $rows = DB::table( 'nexopos_products_unit_quantities' )
            ->where( 'branch_id', $branchId )
            ->count();

        /**
         * Stock has to sit on the base unit alone. A non base row holding a figure would mean
         * the same goods could be sold twice, so it is worth seeing the number is zero.
         */
        $strayStock = DB::table( 'nexopos_products_unit_quantities as uq' )
            ->join( 'nexopos_units as u', 'u.id', '=', 'uq.unit_id' )
            ->join( 'nexopos_products as p', 'p.id', '=', 'uq.product_id' )
            ->where( 'uq.branch_id', $branchId )
            ->where( 'p.stock_on_base_unit', true )
            ->where( 'u.base_unit', false )
            ->where( 'uq.quantity', '>', 0 )
            ->count();

        /**
         * A label that can be scanned while priced at nothing is the one mistake that costs
         * real goods, so it is checked rather than assumed.
         */
        $freeToScan = DB::table( 'nexopos_products_unit_quantities as uq' )
            ->join( 'nexopos_products as p', 'p.id', '=', 'uq.product_id' )
            ->where( 'uq.branch_id', $branchId )
            ->where( 'uq.sale_price', '<=', 0 )
            ->where( 'uq.visible', true )
            ->whereNotNull( 'uq.barcode' )
            ->where( 'uq.barcode', '<>', '' )
            ->where( 'p.status', Product::STATUS_AVAILABLE )
            ->count();

        $this->info( "สินค้าหลายหน่วยในสาขา {$branchId} : {$multiUnit}" );
        $this->info( "แถวสต็อก/ราคาทั้งสาขา        : {$rows}" );

        if ( $strayStock > 0 ) {
            $this->error( "แถวที่ไม่ใช่หน่วยฐานแต่มีสต็อก : {$strayStock} (ต้องเป็น 0)" );
        } else {
            $this->info( 'แถวที่ไม่ใช่หน่วยฐานแต่มีสต็อก : 0 (ถูกต้อง)' );
        }

        if ( $freeToScan > 0 ) {
            $this->error( "บาร์โค้ดที่ยิงได้แต่ราคา 0     : {$freeToScan} (ต้องเป็น 0 ไม่งั้นแจกของฟรี)" );

            return;
        }

        $this->info( 'บาร์โค้ดที่ยิงได้แต่ราคา 0     : 0 (ถูกต้อง)' );
    }
}
