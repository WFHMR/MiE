<?php

namespace Tests\Feature;

use App\Http\Controllers\InventoryUploadController;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductHistory;
use App\Models\ProductUnitQuantity;
use App\Models\Unit;
use App\Models\UnitGroup;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * What dashboard/inventory/upload does with the file the shop exports.
 *
 * The page reads a branch export straight into stock and onto the till. It used to read the
 * file with a fixed layout — skip two lines, third is the header — while the export carries one
 * title line, so the first row of goods became the column names and nothing was ever imported.
 * Several column names were spelt differently from the file as well.
 *
 * The controller is exercised directly rather than over HTTP. Sanctum::actingAs installs a
 * RequestGuard, and Laravel's AuthenticateSession middleware on the dashboard group calls
 * viaRemember() on it, which that guard does not implement — so any test posting to a dashboard
 * web route fails inside the framework before reaching application code. Calling the action
 * tests the behaviour that matters without asserting on a harness limitation.
 */
class InventoryUploadPageTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    private const HEADER = 'รหัสสต็อก,รหัสบาร์โค๊ดสินค้า,ชื่อ/รายละเอียดสินค้า,ปริมาณ/ขนาด,หน่วย,บรรจุ,หน่วยขาย,กลุ่มหลัก,ย่่อย,ผู้จำหน่าย,ชื่อ/บริษัท,ราคาทุน,ขาย (VAT),จำนวน';

    private function branchId(): int
    {
        $branch = Branch::first();

        if ( ! $branch instanceof Branch ) {
            $branch = new Branch;
            $branch->name = 'สาขาทดสอบอัพโหลด';
            $branch->code = 'UPL' . random_int( 100, 999 );
            $branch->is_main = false;
            $branch->author_id = 1;
            $branch->save();
        }

        return (int) $branch->id;
    }

    /**
     * @param list<string> $dataLines
     */
    private function import( array $dataLines, ?int $branchId = null, string $mode = 'set' ): void
    {
        $csv = implode( "\n", array_merge(
            [ 'รายการสินค้า-สาขาทดสอบ,,,,,,,,,,,,,', self::HEADER ],
            $dataLines
        ) ) . "\n";

        $file = UploadedFile::fake()->createWithContent( 'branch-upload.csv', $csv );

        $request = Request::create(
            '/dashboard/inventory/upload',
            'POST',
            [
                'branch_id' => $branchId ?? $this->branchId(),
                'stock_mode' => $mode,
            ],
            [],
            [ 'csv_file' => $file ]
        );

        app()->make( InventoryUploadController::class )->upload( $request );
    }

    private function row( string $barcode, string $name, string $unit, float $sale, float $qty, float $cost = 10.0, float $pack = 1 ): string
    {
        return sprintf(
            'ST-%s,%s,"%s",155,กรัม,%s,%s,7,3,SP1,"ผู้จำหน่ายทดสอบ",%.2f,%.2f,%s',
            $barcode,
            $barcode,
            $name,
            rtrim( rtrim( number_format( $pack, 2, '.', '' ), '0' ), '.' ),
            $unit,
            $cost,
            $sale,
            rtrim( rtrim( number_format( $qty, 2, '.', '' ), '0' ), '.' )
        );
    }

    /**
     * The headline claim: the goods in the file become products that are priced, stocked and
     * scannable.
     */
    public function test_a_new_barcode_becomes_a_priced_stocked_scannable_product(): void
    {
        $this->attemptAuthenticate();

        $branchId = $this->branchId();

        $this->import( [ $this->row( '9990000000017', 'สินค้าทดสอบอัพโหลด หนึ่ง', 'ชิ้น', 25.0, 7.0 ) ], $branchId );

        $product = Product::where( 'barcode', '9990000000017' )->first();

        $this->assertNotNull( $product, 'The upload created no product.' );
        $this->assertSame( 'สินค้าทดสอบอัพโหลด หนึ่ง', $product->name );
        $this->assertSame( Product::STATUS_AVAILABLE, $product->status );

        $row = ProductUnitQuantity::where( 'product_id', $product->id )
            ->where( 'branch_id', $branchId )
            ->first();

        $this->assertNotNull( $row, 'No unit row means no price and no stock.' );
        $this->assertSame( '9990000000017', (string) $row->barcode, 'The unit row carries no barcode, so a scan cannot reach it.' );
        $this->assertSame( 25.0, (float) $row->sale_price );
        $this->assertSame( 7.0, (float) $row->quantity );
        $this->assertTrue( (bool) $row->visible );
    }

    /**
     * The shop quotes prices with VAT inside. A row left with only sale_price makes the receipt
     * and the VAT return disagree with the till.
     */
    public function test_the_vat_split_is_computed(): void
    {
        $this->attemptAuthenticate();

        $this->import( [ $this->row( '9990000000024', 'สินค้าทดสอบ VAT', 'ชิ้น', 107.0, 1.0 ) ] );

        $product = Product::where( 'barcode', '9990000000024' )->first();

        $this->assertNotNull( $product );

        $row = ProductUnitQuantity::where( 'product_id', $product->id )->first();

        $this->assertNotNull( $row );
        $this->assertEqualsWithDelta( 100.0, (float) $row->sale_price_net, 0.01, 'The net was not taken out of the inclusive price.' );
        $this->assertEqualsWithDelta( 7.0, (float) $row->sale_price_tax, 0.01 );
    }

    /**
     * The unit row has to sit in the product's own unit group, or the till cannot resolve what
     * it is selling.
     */
    public function test_the_unit_belongs_to_the_products_unit_group(): void
    {
        $this->attemptAuthenticate();

        $this->import( [ $this->row( '9990000000031', 'สินค้าทดสอบหน่วย', 'กระปุก', 55.0, 4.0 ) ] );

        $product = Product::where( 'barcode', '9990000000031' )->first();

        $this->assertNotNull( $product );

        $row = ProductUnitQuantity::where( 'product_id', $product->id )->first();
        $unit = Unit::find( $row->unit_id );

        $this->assertNotNull( $unit );
        $this->assertSame( 'กระปุก', $unit->name, 'The unit came from หน่วยขาย, not a hardcoded default.' );
        $this->assertSame( (int) $product->unit_group, (int) $unit->group_id );
    }

    /**
     * The category columns in the real export are กลุ่มหลัก and ย่่อย, the second carrying a
     * stray extra tone mark. Reading neither files everything into one bucket.
     */
    public function test_the_category_comes_from_the_file(): void
    {
        $this->attemptAuthenticate();

        $this->import( [ $this->row( '9990000000048', 'สินค้าทดสอบหมวด', 'ชิ้น', 30.0, 2.0 ) ] );

        $product = Product::where( 'barcode', '9990000000048' )->first();

        $this->assertNotNull( $product );

        $category = ProductCategory::find( $product->category_id );

        $this->assertNotNull( $category );
        $this->assertStringContainsString( '7', (string) $category->name, 'The กลุ่มหลัก column was not read.' );
    }

    /**
     * A product with no price must not be offered, which is the rule the barcode work
     * established. Publishing one hands the goods over for nothing.
     */
    public function test_a_row_with_no_price_is_loaded_but_withheld(): void
    {
        $this->attemptAuthenticate();

        $this->import( [ $this->row( '9990000000055', 'สินค้าทดสอบไม่มีราคา', 'ชิ้น', 0.0, 5.0 ) ] );

        $product = Product::where( 'barcode', '9990000000055' )->first();

        $this->assertNotNull( $product, 'The unpriced row was not loaded at all.' );
        $this->assertSame( Product::STATUS_UNAVAILABLE, $product->status );
    }

    /**
     * Uploading the same file twice must refresh the row, not add a second one.
     */
    public function test_uploading_again_refreshes_rather_than_duplicates(): void
    {
        $this->attemptAuthenticate();

        $branchId = $this->branchId();

        $this->import( [ $this->row( '9990000000062', 'สินค้าทดสอบซ้ำ', 'ชิ้น', 20.0, 3.0 ) ], $branchId );
        $this->import( [ $this->row( '9990000000062', 'สินค้าทดสอบซ้ำ', 'ชิ้น', 24.0, 9.0 ) ], $branchId );

        $product = Product::where( 'barcode', '9990000000062' )->first();

        $this->assertNotNull( $product );

        $rows = ProductUnitQuantity::where( 'product_id', $product->id )->where( 'branch_id', $branchId )->get();

        $this->assertCount( 1, $rows, 'A second upload created a duplicate row.' );
        $this->assertSame( 24.0, (float) $rows->first()->sale_price );
        $this->assertSame( 9.0, (float) $rows->first()->quantity );
    }

    /**
     * The shop's daily case: sixty eight on hand, a delivery note of thirty seven, which is a
     * hundred and five on the shelf. Reading that note as a stock count would have thrown away
     * the sixty eight.
     */
    public function test_receiving_adds_to_what_is_already_on_hand(): void
    {
        $this->attemptAuthenticate();

        $branchId = $this->branchId();

        $this->import( [ $this->row( '9990000000109', 'สินค้าทดสอบรับเข้า', 'ชิ้น', 20.0, 68.0 ) ], $branchId, 'set' );

        $product = Product::where( 'barcode', '9990000000109' )->first();
        $this->assertNotNull( $product );
        $this->assertSame( 68.0, (float) ProductUnitQuantity::where( 'product_id', $product->id )->value( 'quantity' ) );

        $this->import( [ $this->row( '9990000000109', 'สินค้าทดสอบรับเข้า', 'ชิ้น', 20.0, 37.0 ) ], $branchId, 'receive' );

        $this->assertSame(
            105.0,
            (float) ProductUnitQuantity::where( 'product_id', $product->id )->value( 'quantity' ),
            'Receiving thirty seven against sixty eight has to leave a hundred and five.'
        );
    }

    public function test_setting_replaces_what_is_on_hand(): void
    {
        $this->attemptAuthenticate();

        $branchId = $this->branchId();

        $this->import( [ $this->row( '9990000000116', 'สินค้าทดสอบตั้งยอด', 'ชิ้น', 20.0, 68.0 ) ], $branchId, 'set' );
        $this->import( [ $this->row( '9990000000116', 'สินค้าทดสอบตั้งยอด', 'ชิ้น', 20.0, 37.0 ) ], $branchId, 'set' );

        $product = Product::where( 'barcode', '9990000000116' )->first();

        $this->assertNotNull( $product );
        $this->assertSame( 37.0, (float) ProductUnitQuantity::where( 'product_id', $product->id )->value( 'quantity' ) );
    }

    /**
     * A mode has to be chosen. Guessing either way costs the shop goods or money, so an upload
     * without one is refused rather than defaulted.
     */
    public function test_an_upload_without_a_mode_is_refused(): void
    {
        $this->attemptAuthenticate();

        $request = Request::create(
            '/dashboard/inventory/upload',
            'POST',
            [ 'branch_id' => $this->branchId() ],
            [],
            [ 'csv_file' => UploadedFile::fake()->createWithContent( 'x.csv', "a,b\n" ) ]
        );

        $refused = false;

        /**
         * Caught as a Throwable rather than a named validation exception because this
         * application replaces the validation failure with its own type.
         */
        try {
            app()->make( InventoryUploadController::class )->upload( $request );
        } catch ( \Throwable $e ) {
            $refused = true;

            $errors = $e instanceof ValidationException ? $e->errors() : [];

            $this->assertTrue(
                array_key_exists( 'stock_mode', $errors ) || str_contains( $e->getMessage(), 'รับสินค้าเข้า' ),
                sprintf( 'Refused, but not because of the missing mode: %s', $e->getMessage() )
            );
        }

        $this->assertTrue( $refused, 'An upload with no mode chosen was accepted.' );
    }

    /**
     * Both modes have to leave a trace, or a wrong upload cannot be traced back afterwards.
     */
    public function test_every_stock_movement_is_recorded_in_the_history(): void
    {
        $this->attemptAuthenticate();

        $branchId = $this->branchId();

        $this->import( [ $this->row( '9990000000123', 'สินค้าทดสอบประวัติ', 'ชิ้น', 20.0, 10.0 ) ], $branchId, 'set' );

        $product = Product::where( 'barcode', '9990000000123' )->first();
        $this->assertNotNull( $product );

        $before = ProductHistory::where( 'product_id', $product->id )->count();

        $this->import( [ $this->row( '9990000000123', 'สินค้าทดสอบประวัติ', 'ชิ้น', 20.0, 5.0 ) ], $branchId, 'receive' );

        $history = ProductHistory::where( 'product_id', $product->id )->orderByDesc( 'id' )->first();

        $this->assertGreaterThan( $before, ProductHistory::where( 'product_id', $product->id )->count(), 'No history row was written.' );
        $this->assertNotNull( $history );
        $this->assertSame( ProductHistory::ACTION_ADDED, (string) $history->operation_type );
        $this->assertSame( 10.0, (float) $history->before_quantity );
        $this->assertSame( 5.0, (float) $history->quantity );
        $this->assertSame( 15.0, (float) $history->after_quantity );
    }

    /**
     * A stock count that finds fewer goods is a removal, not a receipt, or the history would
     * read as though goods arrived while they in fact went missing.
     */
    public function test_setting_a_lower_figure_is_recorded_as_a_removal(): void
    {
        $this->attemptAuthenticate();

        $branchId = $this->branchId();

        $this->import( [ $this->row( '9990000000130', 'สินค้าทดสอบนับขาด', 'ชิ้น', 20.0, 20.0 ) ], $branchId, 'set' );
        $this->import( [ $this->row( '9990000000130', 'สินค้าทดสอบนับขาด', 'ชิ้น', 20.0, 12.0 ) ], $branchId, 'set' );

        $product = Product::where( 'barcode', '9990000000130' )->first();
        $this->assertNotNull( $product );

        $history = ProductHistory::where( 'product_id', $product->id )->orderByDesc( 'id' )->first();

        $this->assertNotNull( $history );
        $this->assertSame( ProductHistory::ACTION_REMOVED, (string) $history->operation_type );
        $this->assertSame( 8.0, (float) $history->quantity );
        $this->assertSame( 12.0, (float) $history->after_quantity );
    }

    /**
     * Receiving against a larger unit converts before it adds, and the addition lands on the
     * base unit where the stock lives.
     */
    public function test_receiving_in_a_larger_unit_converts_before_adding(): void
    {
        $this->attemptAuthenticate();

        $branchId = $this->branchId();

        [ $product, $base, $carton ] = $this->makeLadderProduct( $branchId, baseQuantity: 24.0 );

        $this->import(
            [ $this->row( '9990000000086', 'สินค้าทดสอบชั้นหน่วย', 'หีบอัพโหลด', 480.0, 2.0 ) ],
            $branchId,
            'receive'
        );

        $baseRow = ProductUnitQuantity::where( 'product_id', $product->id )->where( 'unit_id', $base->id )->first();
        $cartonRow = ProductUnitQuantity::where( 'product_id', $product->id )->where( 'unit_id', $carton->id )->first();

        $this->assertSame(
            72.0,
            (float) $baseRow->quantity,
            'Twenty four on hand plus two cases of twenty four is seventy two pieces.'
        );

        $this->assertSame( 0.0, (float) $cartonRow->quantity, 'Stock must not sit on a label row.' );
        $this->assertTrue( (bool) $product->refresh()->stock_on_base_unit, 'The ladder was flattened.' );
    }

    /**
     * The reason this page must not simply create single unit products for everything: goods
     * that sell as ชิ้น, แพ็ค and หีบ keep that ladder, and a count stated in the larger unit is
     * converted into base units because stock lives on the base unit alone.
     */
    public function test_a_ladder_product_keeps_its_units_and_the_count_is_converted(): void
    {
        $this->attemptAuthenticate();

        $branchId = $this->branchId();

        [ $product, $base, $carton ] = $this->makeLadderProduct( $branchId );

        /**
         * The file counts two cases against the carton barcode.
         */
        $this->import( [ $this->row( '9990000000086', 'สินค้าทดสอบชั้นหน่วย', 'หีบอัพโหลด', 480.0, 2.0 ) ], $branchId, 'set' );

        $product->refresh();

        $this->assertTrue( (bool) $product->stock_on_base_unit, 'The unit ladder was flattened.' );

        $this->assertCount(
            2,
            ProductUnitQuantity::where( 'product_id', $product->id )->where( 'branch_id', $branchId )->get(),
            'The ladder lost or gained a row.'
        );

        $cartonRow = ProductUnitQuantity::where( 'product_id', $product->id )->where( 'unit_id', $carton->id )->first();
        $baseRow = ProductUnitQuantity::where( 'product_id', $product->id )->where( 'unit_id', $base->id )->first();

        $this->assertSame( 480.0, (float) $cartonRow->sale_price, 'The carton price was not refreshed.' );
        $this->assertSame( 0.0, (float) $cartonRow->quantity, 'Stock must not sit on a label row.' );
        $this->assertSame( 48.0, (float) $baseRow->quantity, 'Two cases of twenty four is forty eight pieces on the base unit.' );
    }

    /**
     * A product selling as ชิ้น and หีบ ×24, stock held on the base unit.
     *
     * @return array{0: Product, 1: Unit, 2: Unit}
     */
    private function makeLadderProduct( int $branchId, float $baseQuantity = 0.0 ): array
    {
        $group = new UnitGroup;
        $group->name = 'อัพโหลด ชิ้น / หีบ ×24 ' . uniqid();
        $group->author_id = 1;
        $group->save();

        $base = new Unit;
        $base->name = 'ชิ้นอัพโหลด';
        $base->identifier = 'upl-piece-' . uniqid();
        $base->group_id = $group->id;
        $base->value = 1;
        $base->base_unit = true;
        $base->author_id = 1;
        $base->save();

        $carton = new Unit;
        $carton->name = 'หีบอัพโหลด';
        $carton->identifier = 'upl-carton-' . uniqid();
        $carton->group_id = $group->id;
        $carton->value = 24;
        $carton->base_unit = false;
        $carton->author_id = 1;
        $carton->save();

        $product = new Product;
        $product->name = 'สินค้าทดสอบชั้นหน่วย';
        $product->barcode = '9990000000079';
        $product->sku = '9990000000079';
        $product->barcode_type = 'code128';
        $product->unit_group = $group->id;
        $product->author_id = 1;
        $product->type = Product::TYPE_MATERIALIZED;
        $product->product_type = 'product';
        $product->status = Product::STATUS_AVAILABLE;
        $product->stock_management = Product::STOCK_MANAGEMENT_ENABLED;
        $product->tax_type = 'inclusive';
        $product->parent_id = 0;
        $product->on_expiration = Product::EXPIRES_PREVENT_SALES;
        $product->expires = false;
        $product->searchable = true;
        $product->stock_on_base_unit = true;
        $product->save();

        foreach ( [ [ $base->id, '9990000000079', $baseQuantity ], [ $carton->id, '9990000000086', 0.0 ] ] as [ $unitId, $barcode, $quantity ] ) {
            $row = new ProductUnitQuantity;
            $row->product_id = $product->id;
            $row->unit_id = $unitId;
            $row->branch_id = $branchId;
            $row->barcode = $barcode;
            $row->quantity = $quantity;
            $row->sale_price = 10;
            $row->type = 'product';
            $row->visible = true;
            $row->save();
        }

        return [ $product, $base, $carton ];
    }

    /**
     * A barcode Excel turned into 2.00E+66 has lost its digits. Loading it would publish goods
     * under a code that cannot be scanned, so the row is refused.
     */
    public function test_a_barcode_mangled_by_the_spreadsheet_is_refused(): void
    {
        $this->attemptAuthenticate();

        $this->import( [ $this->row( '2.00E+66', 'สินค้าทดสอบบาร์โค้ดเสีย', 'ชิ้น', 45.0, 1.0 ) ] );

        $this->assertNull(
            Product::where( 'name', 'สินค้าทดสอบบาร์โค้ดเสีย' )->first(),
            'A product was created under a barcode whose digits are gone.'
        );
    }

    /**
     * The header is found by its own content, so a file with a different number of title lines
     * above it still loads.
     */
    public function test_the_header_is_found_wherever_it_sits(): void
    {
        $this->attemptAuthenticate();

        $csv = implode( "\n", [
            'รายงานแสดงทะเบียนรายการสินค้า,,,,,,,,,,,,,',
            'ระหว่างวันที่ 1-30,,,,,,,,,,,,,',
            'พิมพ์วันที่ 12/09/2026,,,,,,,,,,,,,',
            self::HEADER,
            $this->row( '9990000000093', 'สินค้าทดสอบหัวตารางลึก', 'ชิ้น', 15.0, 6.0 ),
        ] ) . "\n";

        $request = Request::create(
            '/dashboard/inventory/upload',
            'POST',
            [ 'branch_id' => $this->branchId(), 'stock_mode' => 'set' ],
            [],
            [ 'csv_file' => UploadedFile::fake()->createWithContent( 'deep.csv', $csv ) ]
        );

        app()->make( InventoryUploadController::class )->upload( $request );

        $this->assertNotNull(
            Product::where( 'barcode', '9990000000093' )->first(),
            'A file with three title lines was not read.'
        );
    }
}
