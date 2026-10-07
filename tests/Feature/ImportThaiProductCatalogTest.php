<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnitQuantity;
use App\Models\Provider;
use App\Models\Tax;
use App\Models\TaxGroup;
use App\Models\Unit;
use App\Models\UnitGroup;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Covers rgbf:import-catalog, which loads the shop's real 15k row workbook.
 *
 * The fixture reproduces the exact layout of that file: two title rows, a header
 * on row 3, data from row 4, prices already including VAT, and the awkward cases
 * that actually occur in it — duplicated stock codes, a missing barcode, unit
 * names spelled several ways, numeric junk in the unit column, a zero price and a
 * price below cost.
 */
class ImportThaiProductCatalogTest extends TestCase
{
    /**
     * The suite shares one sqlite fixture and has no RefreshDatabase, so a test
     * that writes a whole catalogue has to roll itself back. Without this the
     * imported categories, single-unit groups and VAT tax group leaked into later
     * tests: CreateProductTest picked one of the one-unit groups and could no
     * longer convert between units, and the coupon test lost its expected totals.
     */
    use DatabaseTransactions;

    private string $workbook;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workbook = storage_path( 'framework/testing/catalog-' . uniqid() . '.xlsx' );

        if ( ! is_dir( dirname( $this->workbook ) ) ) {
            mkdir( dirname( $this->workbook ), 0777, true );
        }

        $this->writeWorkbook();
    }

    protected function tearDown(): void
    {
        if ( is_file( $this->workbook ) ) {
            unlink( $this->workbook );
        }

        parent::tearDown();
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function rows(): array
    {
        //  stock,        barcode,          name,                     packing,  main, sub,  supplier code, supplier,        created,    unit,      cost,  price, stock unit, qty
        return [
            [ 'S-001', '8850001000011', 'ผงวุ้น เขียวโต 50 กรัม', '50 กรัม', '01', '05', 'SP001', 'บจก. ทดสอบหนึ่ง', '01/01/60', 'ถุง', 90.0, 107.0, 'ถุง', 12 ],
            [ 'S-001', '8850001000028', 'แบะแซ ตรากระต่าย ปี๊บ', '', '01', '21', 'SP001', 'บจก. ทดสอบหนึ่ง', '01/01/60', 'ปี๊ป', 700.0, 856.0, 'ปี๊บ', 3 ],
            [ 'S-002', '8850001000035', 'กล่องคัพเค้ก 4 หลุม', '10 ใบ', '02', '11', 'SP002', 'บจก. ทดสอบสอง', '01/01/61', 'แพ้ค', 40.0, 53.5, 'แพ็ค', 40 ],
            [ 'S-003', '8850001000042', 'ช็อกโกแลตแบร์รี่ 500 กรัม', '', '04', '02', 'SP002', 'บจก. ทดสอบสอง', '01/01/62', 'กล่อง', 300.0, 321.0, 'กล่อง', 7 ],
            [ 'S-004', '', 'สินค้าไม่มีบาร์โค้ด', '', '06', '01', 'SP003', 'บจก. ทดสอบสาม', '01/01/63', '1608', 10.0, 21.4, 'ชิ้น', 1 ],
            [ 'S-005', '8850001000066', 'สินค้ายังไม่ตั้งราคา', '', '11', '01', '', '', '01/01/64', 'แกลอน', 25.0, 0.0, 'แกลลอน', 5 ],
            [ 'S-006', '8850001000073', 'สินค้าขายต่ำกว่าทุน', '', '', '', 'SP003', 'บจก. ทดสอบสาม', '01/01/65', 'ขวด', 200.0, 100.0, 'ขวด', 2 ],
        ];
    }

    private function writeWorkbook(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue( 'A1', 'รายงานรายการสินค้าที่ขายได้' );
        $sheet->setCellValue( 'A2', 'ระหว่างวันที่ 01/01/2568 ถึงวันที่ 31/12/2568' );

        foreach ( [ 'อันดับ', 'รหัสสต๊อก', 'รหัสบาร์โค๊ตสินค้า', 'ชื่อ/รายละเอียดสินค้า' ] as $index => $label ) {
            $sheet->setCellValue( [ $index + 1, 3 ], $label );
        }

        $row = 4;

        /**
         * Codes and barcodes have to be written as explicit strings, otherwise
         * "01" is stored as the number 1 and the leading zero is lost.
         */
        foreach ( $this->rows() as $index => $data ) {
            $sheet->setCellValue( [ 1, $row ], $index + 1 );
            $sheet->setCellValue( [ 2, $row ], $data[ 0 ] );
            $sheet->setCellValueExplicit( [ 3, $row ], (string) $data[ 1 ], DataType::TYPE_STRING );
            $sheet->setCellValue( [ 4, $row ], $data[ 2 ] );
            $sheet->setCellValue( [ 6, $row ], $data[ 3 ] );
            $sheet->setCellValueExplicit( [ 7, $row ], (string) $data[ 4 ], DataType::TYPE_STRING );
            $sheet->setCellValueExplicit( [ 8, $row ], (string) $data[ 5 ], DataType::TYPE_STRING );
            $sheet->setCellValue( [ 10, $row ], $data[ 6 ] );
            $sheet->setCellValue( [ 11, $row ], $data[ 7 ] );
            $sheet->setCellValue( [ 12, $row ], $data[ 8 ] );
            $sheet->setCellValue( [ 13, $row ], $data[ 9 ] );
            $sheet->setCellValue( [ 14, $row ], $data[ 10 ] );
            $sheet->setCellValue( [ 15, $row ], $data[ 11 ] );
            $sheet->setCellValue( [ 16, $row ], $data[ 12 ] );
            $sheet->setCellValue( [ 17, $row ], $data[ 13 ] );
            $row++;
        }

        ( new Xlsx( $spreadsheet ) )->save( $this->workbook );
    }

    private function import( array $options = [] ): int
    {
        return Artisan::call( 'rgbf:import-catalog', array_merge( [ 'file' => $this->workbook ], $options ) );
    }

    public function test_dry_run_writes_nothing(): void
    {
        $before = Product::count();

        $this->assertSame( 0, $this->import( [ '--dry-run' => true ] ) );
        $this->assertSame( $before, Product::count() );
    }

    public function test_it_imports_every_row(): void
    {
        $this->assertSame( 0, $this->import() );

        foreach ( $this->rows() as $data ) {
            $this->assertSame(
                1,
                Product::where( 'name', $data[ 2 ] )->count(),
                'Missing product: ' . $data[ 2 ]
            );
        }
    }

    public function test_prices_are_treated_as_vat_inclusive(): void
    {
        $this->import();

        $product = Product::where( 'name', 'ผงวุ้น เขียวโต 50 กรัม' )->firstOrFail();

        $this->assertSame( 'inclusive', $product->tax_type );
        $this->assertNotEmpty( $product->tax_group_id );

        $unitQuantity = ProductUnitQuantity::where( 'product_id', $product->id )->firstOrFail();

        /**
         * 107.00 including 7% VAT means 100.00 net and 7.00 tax exactly.
         */
        $this->assertEquals( 107.0, round( (float) $unitQuantity->sale_price_gross, 2 ) );
        $this->assertEquals( 100.0, round( (float) $unitQuantity->sale_price_net, 2 ) );
        $this->assertEquals( 7.0, round( (float) $unitQuantity->sale_price_tax, 2 ) );
        $this->assertEquals(
            round( (float) $unitQuantity->sale_price_gross, 2 ),
            round( (float) $unitQuantity->sale_price_net + (float) $unitQuantity->sale_price_tax, 2 )
        );
    }

    public function test_a_vat_group_of_exactly_seven_percent_is_created(): void
    {
        $this->import();

        $group = TaxGroup::where( 'name', 'VAT 7%' )->firstOrFail();

        $this->assertSame( 1, Tax::where( 'tax_group_id', $group->id )->count() );
        $this->assertEquals( 7.0, (float) Tax::where( 'tax_group_id', $group->id )->value( 'rate' ) );
    }

    public function test_a_product_without_a_price_is_imported_but_not_sellable(): void
    {
        $this->import();

        $product = Product::where( 'name', 'สินค้ายังไม่ตั้งราคา' )->firstOrFail();

        $this->assertSame(
            Product::STATUS_UNAVAILABLE,
            $product->status,
            'A product priced at zero must not be sellable, it would be given away.'
        );
    }

    public function test_a_priced_product_is_sellable_and_tracks_stock(): void
    {
        $this->import();

        $product = Product::where( 'name', 'ช็อกโกแลตแบร์รี่ 500 กรัม' )->firstOrFail();

        $this->assertSame( Product::STATUS_AVAILABLE, $product->status );
        $this->assertSame( Product::STOCK_MANAGEMENT_ENABLED, $product->stock_management );
        $this->assertEquals( 7, (float) ProductUnitQuantity::where( 'product_id', $product->id )->value( 'quantity' ) );
    }

    public function test_cost_is_stored_as_cogs(): void
    {
        $this->import();

        $product = Product::where( 'name', 'ช็อกโกแลตแบร์รี่ 500 กรัม' )->firstOrFail();

        $this->assertEquals( 300.0, round( (float) ProductUnitQuantity::where( 'product_id', $product->id )->value( 'cogs' ), 2 ) );
    }

    public function test_duplicated_stock_codes_do_not_collide(): void
    {
        $this->import();

        /**
         * The first two rows share stock code S-001. Both must survive as
         * separate products, keyed on their barcode.
         */
        $this->assertSame( 1, Product::where( 'barcode', '8850001000011' )->count() );
        $this->assertSame( 1, Product::where( 'barcode', '8850001000028' )->count() );
    }

    public function test_a_row_without_a_barcode_still_gets_a_unique_identity(): void
    {
        $this->import();

        $product = Product::where( 'name', 'สินค้าไม่มีบาร์โค้ด' )->firstOrFail();

        $this->assertNotEmpty( $product->barcode );
        $this->assertNotEmpty( $product->sku );
    }

    public function test_unit_spellings_are_merged(): void
    {
        $this->import();

        /**
         * ปี๊ป -> ปี๊บ, แพ้ค -> แพ็ค, แกลอน -> แกลลอน, and the numeric "1608"
         * falls back to ชิ้น.
         *
         * Compared in PHP rather than with a WHERE clause. MySQL's utf8mb4_unicode_ci
         * follows UCA 4.0.0, which gives Thai tone marks no weight, so "แพ้ค" and "แพ็ค"
         * are one and the same string to the database: a query for the misspelling matched
         * the row holding the correct spelling and the assertion failed even though the
         * import had done its job. SQLite compares bytes, which is why this only showed up
         * on MySQL.
         */
        $unitNames = Unit::pluck( 'name' )->all();

        $this->assertSame( 1, count( array_keys( $unitNames, 'ปี๊บ', true ) ) );
        $this->assertNotContains( 'ปี๊ป', $unitNames );
        $this->assertNotContains( 'แพ้ค', $unitNames );
        $this->assertNotContains( 'แกลอน', $unitNames );
        $this->assertNotContains( '1608', $unitNames );

        $fallback = Product::where( 'name', 'สินค้าไม่มีบาร์โค้ด' )->firstOrFail();
        $unitId = ProductUnitQuantity::where( 'product_id', $fallback->id )->value( 'unit_id' );

        $this->assertSame( 'ชิ้น', Unit::find( $unitId )->name );
    }

    public function test_each_unit_gets_its_own_group_so_the_product_form_stays_usable(): void
    {
        $this->import();

        foreach ( [ 'ถุง', 'ปี๊บ', 'แพ็ค', 'กล่อง' ] as $name ) {
            $group = UnitGroup::where( 'name', $name )->first();

            $this->assertNotNull( $group, "Missing unit group for {$name}" );
            $this->assertSame( 1, Unit::where( 'group_id', $group->id )->count() );
        }
    }

    public function test_categories_are_named_not_left_as_codes(): void
    {
        $this->import();

        $this->assertSame( 1, ProductCategory::where( 'name', 'วัตถุดิบเบเกอรี่และเครื่องปรุง' )->count() );
        $this->assertSame( 1, ProductCategory::where( 'name', 'ของไหว้และประทัด' )->count() );

        /**
         * Code 11 is folded into the kitchenware category, and a row with no code
         * lands in the explicit fallback rather than being left without a category.
         */
        $kitchenware = ProductCategory::where( 'name', 'อุปกรณ์ครัวและบรรจุภัณฑ์' )->firstOrFail();
        $unpriced = Product::where( 'name', 'สินค้ายังไม่ตั้งราคา' )->firstOrFail();

        $this->assertSame( $kitchenware->id, $unpriced->category_id );

        $noCode = Product::where( 'name', 'สินค้าขายต่ำกว่าทุน' )->firstOrFail();

        $this->assertSame(
            ProductCategory::where( 'name', 'ไม่ระบุหมวดหมู่' )->value( 'id' ),
            $noCode->category_id
        );
    }

    public function test_suppliers_are_created_once_each(): void
    {
        $this->import();

        $this->assertSame( 1, Provider::where( 'first_name', 'บจก. ทดสอบหนึ่ง' )->count() );
        $this->assertSame( 1, Provider::where( 'first_name', 'บจก. ทดสอบสอง' )->count() );
        $this->assertSame( 1, Provider::where( 'first_name', 'บจก. ทดสอบสาม' )->count() );
    }

    public function test_source_fields_without_a_column_are_preserved(): void
    {
        $this->import();

        $product = Product::where( 'name', 'ผงวุ้น เขียวโต 50 กรัม' )->firstOrFail();

        $this->assertStringContainsString( 'S-001', $product->description );
        $this->assertStringContainsString( 'หมวดย่อย: 05', $product->description );
        $this->assertStringContainsString( 'บจก. ทดสอบหนึ่ง', $product->description );
    }

    public function test_running_twice_updates_instead_of_duplicating(): void
    {
        $this->import();

        $products = Product::count();
        $units = Unit::count();
        $providers = Provider::count();
        $categories = ProductCategory::count();

        $this->import();

        $this->assertSame( $products, Product::count(), 'The import is not idempotent.' );
        $this->assertSame( $units, Unit::count() );
        $this->assertSame( $providers, Provider::count() );
        $this->assertSame( $categories, ProductCategory::count() );
        $this->assertSame(
            1,
            ProductUnitQuantity::where( 'product_id', Product::where( 'barcode', '8850001000011' )->value( 'id' ) )->count()
        );
    }

    /**
     * category_id is nullable and a product can outlive its category, but
     * ProductAfterDeleteEventListener passed that null into
     * ProductCategoryService::computeProducts(), which is typed against
     * ProductCategory. Deleting such a product therefore threw a TypeError.
     */
    public function test_a_product_without_a_category_can_be_deleted(): void
    {
        $orphan = new Product;
        $orphan->name = 'สินค้าไม่มีหมวดหมู่';
        $orphan->barcode = 'ORPHAN-' . uniqid();
        $orphan->sku = $orphan->barcode;
        $orphan->barcode_type = 'code128';
        $orphan->category_id = null;
        $orphan->unit_group = UnitGroup::first()?->id ?? 1;
        $orphan->author_id = 1;
        $orphan->save();

        $id = $orphan->id;

        $orphan->delete();

        $this->assertNull( Product::find( $id ) );
    }

    public function test_hide_missing_only_applies_when_asked(): void
    {
        $stray = new Product;
        $stray->name = 'สินค้าที่ไม่อยู่ในไฟล์';
        $stray->barcode = 'NOT-IN-WORKBOOK';
        $stray->sku = 'NOT-IN-WORKBOOK';
        $stray->barcode_type = 'code128';
        $stray->category_id = ProductCategory::first()?->id;
        $stray->unit_group = UnitGroup::first()?->id ?? 1;
        $stray->author_id = 1;
        $stray->status = Product::STATUS_AVAILABLE;
        $stray->searchable = true;
        $stray->save();

        $this->import();

        $this->assertSame(
            Product::STATUS_AVAILABLE,
            Product::find( $stray->id )->status,
            'A plain import must not hide products it did not see.'
        );

        $this->import( [ '--hide-missing' => true ] );

        $refreshed = Product::find( $stray->id );

        $this->assertSame( Product::STATUS_UNAVAILABLE, $refreshed->status );
        $this->assertFalse( (bool) $refreshed->searchable );

        $refreshed->delete();
    }
}
