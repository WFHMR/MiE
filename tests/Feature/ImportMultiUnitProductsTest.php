<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\ProductUnitQuantity;
use App\Models\Register;
use App\Models\Unit;
use App\Models\UnitGroup;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The importer decides, for every goods on the shelf, which unit holds the stock, how much of
 * it there is, and what each barcode is worth. Getting any of those wrong misprices or
 * misdeducts every future sale of that item, so each decision is pinned here against small
 * fixtures shaped like the real exports.
 *
 * The cases are drawn from what the actual files contain: a carton that breaks into cans, the
 * same goods recorded on two branch lines, a group whose smallest units contradict each other,
 * one unit wearing two barcodes, and goods absent from the registry.
 */
class ImportMultiUnitProductsTest extends TestCase
{
    use DatabaseTransactions;

    private const BRANCH_ID = 77;

    private string $branchFile;

    private string $registryFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branchFile = storage_path( 'app/test-branch-' . uniqid() . '.csv' );
        $this->registryFile = storage_path( 'app/test-registry-' . uniqid() . '.csv' );

        DB::table( 'nexopos_branches' )->insert( [
            'id' => self::BRANCH_ID,
            'code' => 'TEST' . self::BRANCH_ID,
            'name' => 'สาขาทดสอบ',
            'is_main' => false,
            'author_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ] );
    }

    protected function tearDown(): void
    {
        foreach ( [ $this->branchFile, $this->registryFile ] as $file ) {
            if ( is_file( $file ) ) {
                unlink( $file );
            }
        }

        parent::tearDown();
    }

    public function test_a_carton_becomes_a_label_over_the_piece_stock(): void
    {
        $this->writeBranch( [
            // Stock code, barcode, name, pack, sale unit, cost, sale, quantity.
            [ 'S1', 'CARTON1', 'โรซ่า ปลาแมคเคอเรล 190 กรัม', 48, 'หีบ', 950, 1070, 1 ],
        ] );

        $this->writeRegistry( [
            [ 'S1', 'PIECE1', 'โรซ่า ปลาแมคเคอเรล 190 กรัม', 1, 'กระป๋อง', 19.79, 25 ],
            [ 'S1', 'PACK1', 'โรซ่า ปลาแมคเคอเรล 190 กรัม', 4, 'แพ็ค', 79.17, 93 ],
            [ 'S1', 'CARTON1', 'โรซ่า ปลาแมคเคอเรล 190 กรัม', 48, 'หีบ', 950, 1070 ],
        ] );

        $this->runImport();

        $product = Product::where( 'barcode', 'CARTON1' )->first();

        $this->assertInstanceOf( Product::class, $product, 'The goods have to exist as one product.' );
        $this->assertTrue( (bool) $product->stock_on_base_unit, 'Without the flag every label would deduct a single piece.' );

        $rows = $this->rowsByBarcode( $product->id );

        $this->assertSame( [ 'CARTON1', 'PACK1', 'PIECE1' ], array_keys( $rows ), 'Each unit level needs its own barcode row.' );

        $this->assertSame( 25.0, (float) $rows[ 'PIECE1' ]->sale_price );
        $this->assertSame( 93.0, (float) $rows[ 'PACK1' ]->sale_price );
        $this->assertSame( 1070.0, (float) $rows[ 'CARTON1' ]->sale_price );

        /**
         * จำนวน counts cartons, so one carton of forty eight is forty eight cans, and the
         * whole lot sits on the can row.
         */
        $this->assertSame( 48.0, (float) $rows[ 'PIECE1' ]->quantity, 'One carton of forty eight is forty eight cans.' );
        $this->assertSame( 0.0, (float) $rows[ 'PACK1' ]->quantity, 'A label must not hold stock of its own.' );
        $this->assertSame( 0.0, (float) $rows[ 'CARTON1' ]->quantity, 'A label must not hold stock of its own.' );

        $units = $this->unitsOf( $product );

        $this->assertSame( 1.0, (float) $units[ 'กระป๋อง' ]->value );
        $this->assertTrue( (bool) $units[ 'กระป๋อง' ]->base_unit, 'The smallest unit carries the stock.' );
        $this->assertSame( 4.0, (float) $units[ 'แพ็ค' ]->value );
        $this->assertSame( 48.0, (float) $units[ 'หีบ' ]->value );
        $this->assertFalse( (bool) $units[ 'หีบ' ]->base_unit );
    }

    /**
     * The branch records the loose bag and the carton on separate lines. Left as two products
     * the same goods would exist twice and could be sold twice over.
     */
    public function test_two_branch_lines_for_one_goods_become_one_product_with_the_stock_added(): void
    {
        $this->writeBranch( [
            [ 'P004', 'BAG', 'ปรุงทิพย์ เกลือป่น 500 กรัม', 1, 'ถุง', 10, 12, 2 ],
            [ 'P004', 'CASE', 'ปรุงทิพย์ เกลือป่น 500 กรัม', 48, 'หีบ', 480, 550, 1 ],
        ] );

        $this->writeRegistry( [
            [ 'P004', 'BAG', 'ปรุงทิพย์ เกลือป่น 500 กรัม', 1, 'ถุง', 10, 12 ],
            [ 'P004', 'CASE', 'ปรุงทิพย์ เกลือป่น 500 กรัม', 48, 'หีบ', 480, 550 ],
        ] );

        $this->runImport();

        $survivors = Product::whereIn( 'barcode', [ 'BAG', 'CASE' ] )
            ->where( 'status', Product::STATUS_AVAILABLE )
            ->get();

        $this->assertCount( 1, $survivors, 'Only one product may represent the goods.' );

        /**
         * The retired copy keeps nothing that would convert a quantity, since it no longer has
         * the rows the flag governed.
         */
        $retired = Product::whereIn( 'barcode', [ 'BAG', 'CASE' ] )
            ->where( 'status', Product::STATUS_UNAVAILABLE )
            ->get();

        foreach ( $retired as $copy ) {
            $this->assertFalse( (bool) $copy->stock_on_base_unit, 'A retired copy must not keep the flag.' );
            $this->assertSame( 0, ProductUnitQuantity::where( 'product_id', $copy->id )->count() );
        }

        $rows = $this->rowsByBarcode( $survivors->first()->id );

        /**
         * Two bags plus one carton of forty eight is fifty bags. Adding the raw quantities
         * would have given three of nothing in particular.
         */
        $this->assertSame( 50.0, (float) $rows[ 'BAG' ]->quantity );
        $this->assertSame( 0.0, (float) $rows[ 'CASE' ]->quantity );
    }

    /**
     * มิตรผล น้ำตาลทรายขาว lists ถุง and กระสอบ both at บรรจุ 1 though a sack holds twenty five
     * bags. Importing that would deduct one bag for a whole sack, so the goods are left alone.
     */
    public function test_goods_whose_smallest_units_contradict_each_other_are_skipped(): void
    {
        $this->writeBranch( [
            [ 'SUGAR', 'SBAG', 'มิตรผล น้ำตาลทรายขาว 1 กก.', 1, 'ถุง', 20, 25, 2 ],
        ] );

        $this->writeRegistry( [
            [ 'SUGAR', 'SBAG', 'มิตรผล น้ำตาลทรายขาว 1 กก.', 1, 'ถุง', 20, 25 ],
            [ 'SUGAR', 'SSACK', 'มิตรผล น้ำตาลทรายขาว 1 กก.', 1, 'กระสอบ', 500, 600 ],
        ] );

        $this->runImport();

        $this->assertSame(
            0,
            ProductUnitQuantity::where( 'branch_id', self::BRANCH_ID )->count(),
            'A ratio that cannot be read must not be guessed at.'
        );
    }

    /**
     * One retail unit wearing two barcodes. NexoPOS holds a single row per unit, so the first
     * barcode takes it and the second is reported rather than silently creating a second unit
     * that would carry its own stock.
     */
    public function test_one_unit_with_two_barcodes_keeps_a_single_row(): void
    {
        $this->writeBranch( [
            [ 'DUP', 'BAG_A', 'ขนมถุง', 1, 'ถุง', 8, 10, 3 ],
        ] );

        $this->writeRegistry( [
            [ 'DUP', 'BAG_A', 'ขนมถุง', 1, 'ถุง', 8, 10 ],
            [ 'DUP', 'BAG_B', 'ขนมถุง', 1, 'ถุง', 8, 10 ],
            [ 'DUP', 'CASE_C', 'ขนมถุง', 10, 'หีบ', 80, 95 ],
        ] );

        $this->runImport();

        $product = Product::where( 'barcode', 'BAG_A' )->first();
        $rows = $this->rowsByBarcode( $product->id );

        $this->assertSame( [ 'BAG_A', 'CASE_C' ], array_keys( $rows ) );
        $this->assertSame( 3.0, (float) $rows[ 'BAG_A' ]->quantity );

        $this->assertCount( 2, $this->unitsOf( $product ), 'A duplicate barcode must not become a second unit.' );
    }

    /**
     * Two barcodes for the same can, and the branch stocks the second one.
     *
     * The row used to take whichever the register listed first, so the shop held cans printed
     * with a code the till did not know: พริงเกิลส์ answered to 4902430362542 while the shelf
     * carried 8886467100024. The branch file is the record of what is actually on that shelf,
     * so its code is the one that has to be scannable.
     */
    public function test_the_row_takes_the_barcode_the_branch_actually_stocks(): void
    {
        $this->writeBranch( [
            [ 'PICK', 'CAN_B', 'มันฝรั่งทดสอบ', 1, 'กระป๋อง', 39.06, 45, 12 ],
        ] );

        $this->writeRegistry( [
            [ 'PICK', 'CAN_A', 'มันฝรั่งทดสอบ', 1, 'กระป๋อง', 39.06, 45 ],
            [ 'PICK', 'CAN_B', 'มันฝรั่งทดสอบ', 1, 'กระป๋อง', 39.06, 45 ],
            [ 'PICK', 'CASE_P', 'มันฝรั่งทดสอบ', 12, 'หีบ', 468.72, 500 ],
        ] );

        $this->runImport();

        $product = Product::where( 'barcode', 'CAN_B' )->first();

        $this->assertInstanceOf( Product::class, $product );

        $rows = $this->rowsByBarcode( $product->id );

        $this->assertSame( [ 'CAN_B', 'CASE_P' ], array_keys( $rows ) );
        $this->assertArrayNotHasKey( 'CAN_A', $rows, 'The register order decided the row instead of the branch stock.' );
        $this->assertSame( 12.0, (float) $rows[ 'CAN_B' ]->quantity );
    }

    /**
     * The register prices the same bag twice, once at nothing and once for real, and the
     * unpriced line comes first.
     *
     * เซสท์ เนยขาว is listed as ถุง at ฿0 and again as ถุง at ฿80. Taking the first left the
     * base level unpriced, which withholds the entire product — fifteen bags on the shelf that
     * could not be sold although the file states their price. The price used here is the file's
     * own, on the same unit at the same ratio.
     */
    public function test_an_unpriced_line_does_not_win_over_a_priced_one_for_the_same_unit(): void
    {
        $this->writeBranch( [
            [ 'ZB', 'CASE_Z', 'เนยขาวทดสอบ', 15, 'หีบ', 1015.69, 1160, 1 ],
        ] );

        $this->writeRegistry( [
            [ 'ZB', 'BAG_ZERO', 'เนยขาวทดสอบ', 1, 'ถุง', 135.43, 0 ],
            [ 'ZB', 'BAG_PRICED', 'เนยขาวทดสอบ', 1, 'ถุง', 67.71, 80 ],
            [ 'ZB', 'CASE_Z', 'เนยขาวทดสอบ', 15, 'หีบ', 1015.69, 1160 ],
        ] );

        $this->runImport();

        $product = Product::where( 'barcode', 'CASE_Z' )->first();

        $this->assertInstanceOf( Product::class, $product );

        $rows = $this->rowsByBarcode( $product->id );

        $this->assertArrayHasKey( 'BAG_PRICED', $rows, 'The unpriced line was kept and withheld the goods.' );
        $this->assertArrayNotHasKey( 'BAG_ZERO', $rows );

        $this->assertSame( 80.0, (float) $rows[ 'BAG_PRICED' ]->sale_price );
        $this->assertSame( 15.0, (float) $rows[ 'BAG_PRICED' ]->quantity, 'One case of fifteen is fifteen bags.' );

        $this->assertSame(
            Product::STATUS_AVAILABLE,
            $product->status,
            'The base level carries a price, so there is nothing left to withhold.'
        );
    }

    /**
     * The limit of the rule above, on purpose.
     *
     * When the branch itself records the unpriced code, that is the goods it holds, and the
     * priced sibling is a different pack under the same unit name — เม็ดมะม่วง lists one bag at
     * a cost of 28 and another at 70, both as ถุง(บรรจุ 1). Lending the second one's price to
     * the first would misprice real goods, so the product stays withheld until someone prices it.
     */
    public function test_the_branch_choice_is_respected_even_when_it_has_no_price(): void
    {
        $this->writeBranch( [
            [ 'MN', 'BAG_SMALL', 'เม็ดทดสอบ', 1, 'ถุง', 28, 0, 5 ],
            [ 'MN', 'CASE_MN', 'เม็ดทดสอบ', 10, 'ท่อน', 1400, 1950, 1 ],
        ] );

        $this->writeRegistry( [
            [ 'MN', 'BAG_SMALL', 'เม็ดทดสอบ', 1, 'ถุง', 28, 0 ],
            [ 'MN', 'BAG_BIG', 'เม็ดทดสอบ', 1, 'ถุง', 70, 100 ],
            [ 'MN', 'CASE_MN', 'เม็ดทดสอบ', 10, 'ท่อน', 1400, 1950 ],
        ] );

        $this->runImport();

        $product = Product::where( 'barcode', 'BAG_SMALL' )->first();

        $this->assertInstanceOf( Product::class, $product );

        $rows = $this->rowsByBarcode( $product->id );

        $this->assertArrayHasKey( 'BAG_SMALL', $rows );
        $this->assertArrayNotHasKey( 'BAG_BIG', $rows, 'A different pack under the same unit name must not supply the price.' );

        $this->assertSame( 0.0, (float) $rows[ 'BAG_SMALL' ]->sale_price );
        $this->assertSame( Product::STATUS_UNAVAILABLE, $product->status );
    }

    public function test_goods_absent_from_the_registry_are_left_untouched(): void
    {
        $this->writeBranch( [
            [ 'X', 'UNKNOWN', 'สินค้าที่ไม่มีในทะเบียน', 1, 'ชิ้น', 5, 7, 4 ],
        ] );

        $this->writeRegistry( [
            [ 'S1', 'PIECE1', 'อย่างอื่น', 1, 'กระป๋อง', 10, 12 ],
            [ 'S1', 'CARTON1', 'อย่างอื่น', 24, 'หีบ', 240, 280 ],
        ] );

        $this->runImport();

        $this->assertNull(
            Product::where( 'barcode', 'UNKNOWN' )->first(),
            'The importer only rebuilds goods it can read every unit level for.'
        );
    }

    public function test_a_registry_group_with_one_level_is_left_untouched(): void
    {
        $this->writeBranch( [
            [ 'ONE', 'SINGLE', 'สินค้าหน่วยเดียว', 1, 'ขวด', 9, 11, 6 ],
        ] );

        $this->writeRegistry( [
            [ 'ONE', 'SINGLE', 'สินค้าหน่วยเดียว', 1, 'ขวด', 9, 11 ],
        ] );

        $this->runImport();

        $this->assertNull(
            Product::where( 'barcode', 'SINGLE' )->first(),
            'A goods with a single unit gains nothing and is left to the ordinary importer.'
        );
    }

    /**
     * Where the registry never states a retail unit, the smallest level on offer is what the
     * shop sells, and จำนวน is already counted in it.
     */
    public function test_goods_with_no_retail_level_hold_stock_on_the_smallest_unit(): void
    {
        $this->writeBranch( [
            [ 'BOX', 'BOX100', 'กล่องใส E-66 แพ็ค 100 ใบ', 100, 'แพ็ค', 180, 200, 3 ],
        ] );

        $this->writeRegistry( [
            [ 'BOX', 'BOX100', 'กล่องใส E-66 แพ็ค 100 ใบ', 100, 'แพ็ค', 180, 200 ],
            [ 'BOX', 'BOX600', 'กล่องใส E-66 แพ็ค 100 ใบ', 600, 'ลัง', 1080, 1180 ],
        ] );

        $this->runImport();

        $product = Product::where( 'barcode', 'BOX100' )->first();
        $rows = $this->rowsByBarcode( $product->id );
        $units = $this->unitsOf( $product );

        $this->assertSame( 1.0, (float) $units[ 'แพ็ค' ]->value, 'The smallest level becomes the unit of one.' );
        $this->assertSame( 6.0, (float) $units[ 'ลัง' ]->value, 'Six hundred over one hundred is six packs to a case.' );

        /**
         * Three packs, not three hundred: the branch counted in packs and the pack is the
         * smallest thing the registry offers.
         */
        $this->assertSame( 3.0, (float) $rows[ 'BOX100' ]->quantity );
    }

    /**
     * The point of the whole exercise: after the import, a cashier scanning either label gets
     * the right price and the shelf count moves by the right amount. The importer and the
     * deduction are separately tested, so this joins them and checks they agree.
     */
    public function test_after_importing_either_barcode_charges_and_deducts_correctly(): void
    {
        $this->writeBranch( [
            [ 'S1', 'CARTON1', 'โรซ่า ปลาแมคเคอเรล 190 กรัม', 48, 'หีบ', 950, 1070, 2 ],
        ] );

        $this->writeRegistry( [
            [ 'S1', 'PIECE1', 'โรซ่า ปลาแมคเคอเรล 190 กรัม', 1, 'กระป๋อง', 19.79, 25 ],
            [ 'S1', 'CARTON1', 'โรซ่า ปลาแมคเคอเรล 190 กรัม', 48, 'หีบ', 950, 1070 ],
        ] );

        $this->runImport();

        $product = Product::where( 'barcode', 'CARTON1' )->first();
        $rows = $this->rowsByBarcode( $product->id );
        $units = $this->unitsOf( $product );

        $productService = app( ProductService::class );

        $this->assertSame( 96.0, (float) $rows[ 'PIECE1' ]->quantity, 'Two cartons of forty eight is ninety six cans.' );

        /**
         * Scanning the carton. The price charged is the carton's own, and the shelf loses a
         * carton's worth of cans rather than a single can.
         */
        $this->assertSame( 1070.0, (float) $rows[ 'CARTON1' ]->sale_price );

        $productService->stockAdjustment( ProductHistory::ACTION_LOST, [
            'product_id' => $product->id,
            'unit_id' => $units[ 'หีบ' ]->id,
            'quantity' => 1,
            'unit_price' => 1070,
            'author' => 1,
        ] );

        $this->assertSame(
            48.0,
            (float) $productService->getQuantity( $product->id, $units[ 'กระป๋อง' ]->id ),
            'Ninety six cans less one carton of forty eight leaves forty eight cans.'
        );

        /**
         * Scanning a single can off the same shelf. Its own price applies and one can goes.
         */
        $this->assertSame( 25.0, (float) $rows[ 'PIECE1' ]->sale_price );

        $productService->stockAdjustment( ProductHistory::ACTION_LOST, [
            'product_id' => $product->id,
            'unit_id' => $units[ 'กระป๋อง' ]->id,
            'quantity' => 1,
            'unit_price' => 25,
            'author' => 1,
        ] );

        $this->assertSame(
            47.0,
            (float) $productService->getQuantity( $product->id, $units[ 'กระป๋อง' ]->id )
        );

        $this->assertSame(
            0.0,
            (float) $productService->getQuantity( $product->id, $units[ 'หีบ' ]->id ),
            'The carton row stays a label throughout and never accumulates a figure.'
        );
    }

    /**
     * A row a receipt points at has to survive, or refunding and reprinting that sale break.
     * It is emptied and its barcode cleared instead, so it cannot answer a scan or hold stock
     * while the sale it belongs to still reads it.
     */
    public function test_a_stock_row_a_receipt_points_at_is_emptied_rather_than_deleted(): void
    {
        $this->writeBranch( [
            [ 'S1', 'CARTON1', 'สินค้าที่ขายไปแล้ว', 24, 'หีบ', 240, 280, 1 ],
        ] );

        $this->writeRegistry( [
            [ 'S1', 'PIECE1', 'สินค้าที่ขายไปแล้ว', 1, 'ชิ้น', 10, 12 ],
            [ 'S1', 'CARTON1', 'สินค้าที่ขายไปแล้ว', 24, 'หีบ', 240, 280 ],
        ] );

        [ $legacyProduct, $legacyRow ] = $this->makeLegacySingleUnitProduct( 'CARTON1', 'หีบเดิม' );

        $orderProductId = DB::table( 'nexopos_orders_products' )->insertGetId( [
            'order_id' => 0,
            'product_id' => $legacyProduct->id,
            'unit_id' => $legacyRow->unit_id,
            'unit_quantity_id' => $legacyRow->id,
            'name' => 'สินค้าที่ขายไปแล้ว',
            'quantity' => 1,
            'unit_price' => 280,
            'total_price' => 280,
            'product_category_id' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ] );

        $this->runImport();

        $survivingRow = ProductUnitQuantity::find( $legacyRow->id );

        $this->assertInstanceOf(
            ProductUnitQuantity::class,
            $survivingRow,
            'Deleting it would leave the receipt pointing at nothing.'
        );

        $this->assertNull( $survivingRow->barcode, 'A cleared barcode is what stops it answering a scan.' );
        $this->assertSame( 0.0, (float) $survivingRow->quantity );
        $this->assertFalse( (bool) $survivingRow->visible );

        /**
         * The new rows are the ones that answer a scan now, and only one row carries that
         * barcode.
         */
        $carrying = ProductUnitQuantity::where( 'barcode', 'CARTON1' )->get();

        $this->assertCount( 1, $carrying, 'Two rows on one barcode would make the scan ambiguous.' );
        $this->assertSame( 280.0, (float) $carrying->first()->sale_price );

        DB::table( 'nexopos_orders_products' )->where( 'id', $orderProductId )->delete();
    }

    /**
     * An obsolete row nothing points at is simply removed, so the catalogue does not silt up.
     */
    public function test_an_unreferenced_obsolete_row_is_removed(): void
    {
        $this->writeBranch( [
            [ 'S2', 'CARTON2', 'สินค้าที่ยังไม่เคยขาย', 12, 'หีบ', 120, 140, 1 ],
        ] );

        $this->writeRegistry( [
            [ 'S2', 'PIECE2', 'สินค้าที่ยังไม่เคยขาย', 1, 'ชิ้น', 10, 12 ],
            [ 'S2', 'CARTON2', 'สินค้าที่ยังไม่เคยขาย', 12, 'หีบ', 120, 140 ],
        ] );

        [ , $legacyRow ] = $this->makeLegacySingleUnitProduct( 'CARTON2', 'หีบเดิม2' );

        $this->runImport();

        $this->assertNull( ProductUnitQuantity::find( $legacyRow->id ) );
    }

    /**
     * Rebuilding the catalogue under a cashier mid-sale can charge from a row that no longer
     * means what the cart assumed, so an open till stops the import unless it is overridden.
     */
    public function test_an_open_till_stops_the_import(): void
    {
        $this->writeBranch( [
            [ 'S3', 'CARTON3', 'สินค้าทดสอบลิ้นชัก', 12, 'หีบ', 120, 140, 1 ],
        ] );

        $this->writeRegistry( [
            [ 'S3', 'PIECE3', 'สินค้าทดสอบลิ้นชัก', 1, 'ชิ้น', 10, 12 ],
            [ 'S3', 'CARTON3', 'สินค้าทดสอบลิ้นชัก', 12, 'หีบ', 120, 140 ],
        ] );

        $registerId = DB::table( 'nexopos_registers' )->insertGetId( [
            'name' => 'จุดคิดเงินทดสอบ',
            'branch_id' => self::BRANCH_ID,
            'status' => Register::STATUS_OPENED,
            'author_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ] );

        $this->artisan( 'rgbf:import-multiunit-products', [
            'branch-file' => $this->branchFile,
            'registry-file' => $this->registryFile,
            '--branch' => self::BRANCH_ID,
        ] )->assertFailed();

        $this->assertNull( Product::where( 'barcode', 'CARTON3' )->first() );

        // The same run goes ahead once the risk is accepted explicitly.
        $this->runImport( [ '--force' => true ] );

        $this->assertInstanceOf( Product::class, Product::where( 'barcode', 'CARTON3' )->first() );

        DB::table( 'nexopos_registers' )->where( 'id', $registerId )->delete();
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->writeBranch( [
            [ 'S1', 'CARTON1', 'สินค้าทดสอบ', 24, 'หีบ', 240, 280, 1 ],
        ] );

        $this->writeRegistry( [
            [ 'S1', 'PIECE1', 'สินค้าทดสอบ', 1, 'ชิ้น', 10, 12 ],
            [ 'S1', 'CARTON1', 'สินค้าทดสอบ', 24, 'หีบ', 240, 280 ],
        ] );

        $this->runImport( [ '--dry-run' => true ] );

        $this->assertNull( Product::where( 'barcode', 'CARTON1' )->first() );
        $this->assertSame( 0, ProductUnitQuantity::where( 'branch_id', self::BRANCH_ID )->count() );
    }

    /**
     * The registry often lists a carton without a price. Offering it would put a barcode on the
     * shelf that rings up as nothing and hands over a whole carton, so no row is made for it.
     */
    public function test_a_unit_the_registry_never_priced_is_not_offered(): void
    {
        $this->writeBranch( [
            [ 'NP', 'NPCASE', 'สินค้าที่หีบไม่มีราคา', 24, 'หีบ', 0, 0, 1 ],
        ] );

        $this->writeRegistry( [
            [ 'NP', 'NPPIECE', 'สินค้าที่หีบไม่มีราคา', 1, 'ชิ้น', 10, 12 ],
            [ 'NP', 'NPCASE', 'สินค้าที่หีบไม่มีราคา', 24, 'หีบ', 0, 0 ],
        ] );

        $this->runImport();

        $product = Product::where( 'barcode', 'NPCASE' )->first();
        $rows = $this->rowsByBarcode( $product->id );

        $this->assertSame( [ 'NPPIECE' ], array_keys( $rows ), 'Only the priced unit may be offered.' );

        $this->assertSame(
            24.0,
            (float) $rows[ 'NPPIECE' ]->quantity,
            'The stock still arrives through the carton even though the carton is not sellable.'
        );

        $this->assertNull(
            ProductUnitQuantity::where( 'barcode', 'NPCASE' )->first(),
            'A scan of the unpriced carton has to find nothing rather than something free.'
        );

        $this->assertSame(
            Product::STATUS_AVAILABLE,
            $product->status,
            'The goods are still sellable by the piece.'
        );
    }

    /**
     * Re-running has to take away a zero priced label that an earlier pass created, otherwise
     * fixing the bug would need the row deleted by hand.
     */
    public function test_rerunning_removes_a_zero_priced_label_left_by_an_earlier_pass(): void
    {
        $this->writeBranch( [
            [ 'NP2', 'NP2CASE', 'สินค้าที่เคยมีหีบราคา 0', 24, 'หีบ', 0, 0, 1 ],
        ] );

        $this->writeRegistry( [
            [ 'NP2', 'NP2PIECE', 'สินค้าที่เคยมีหีบราคา 0', 1, 'ชิ้น', 10, 12 ],
            [ 'NP2', 'NP2CASE', 'สินค้าที่เคยมีหีบราคา 0', 24, 'หีบ', 0, 0 ],
        ] );

        $this->runImport();

        $product = Product::where( 'barcode', 'NP2CASE' )->first();
        $cartonUnit = $this->unitsOf( $product )[ 'หีบ' ];

        // Put back the row the earlier pass would have written.
        $stray = new ProductUnitQuantity;
        $stray->product_id = $product->id;
        $stray->unit_id = $cartonUnit->id;
        $stray->branch_id = self::BRANCH_ID;
        $stray->barcode = 'NP2CASE';
        $stray->quantity = 0;
        $stray->sale_price = 0;
        $stray->sale_price_edit = 0;
        $stray->cogs = 0;
        $stray->type = 'product';
        $stray->visible = true;
        $stray->save();

        $this->runImport();

        $this->assertNull(
            ProductUnitQuantity::find( $stray->id ),
            'A second pass has to clear the label the first pass should never have made.'
        );
    }

    /**
     * กล่องใส E-66 lists แพ็ค at บรรจุ 100 meaning sheets per pack and หีบ at 30 meaning packs
     * per case. Dividing them gives 3.333 packs to a case, which cannot be sold or counted, so
     * the goods are left alone rather than given a ratio nobody can honour.
     */
    /**
     * A tightening of the reading rules can move goods out of the plan after an earlier pass
     * already rebuilt them. Left flagged, the till would keep converting by a ratio the command
     * has since judged unusable, so those goods are returned to one unit and one price.
     */
    /**
     * The registry says both levels hold one, though the name says a case holds twelve. Rather
     * than infer it, the ratio is supplied and the goods then import normally.
     */
    public function test_a_confirmed_ratio_makes_unreadable_goods_importable(): void
    {
        $this->writeBranch( [
            [ 'BRANCH-CODE', 'FISHCASE', 'น้ำปลาพริก หอยนางรม 12ห่อ/กล่อง', 1, 'หีบ', 350.47, 395, 1 ],
        ] );

        $this->writeRegistry( [
            [ 'FISH', 'FISHBAG', 'น้ำปลาพริก หอยนางรม 12ห่อ/กล่อง', 1, 'ห่อ', 29.21, 34 ],
            [ 'FISH', 'FISHCASE', 'น้ำปลาพริก หอยนางรม 12ห่อ/กล่อง', 1, 'หีบ', 350.47, 395 ],
        ] );

        // Without the ratio the goods are refused, because two units cannot both hold one.
        $this->runImport();

        $this->assertNull( Product::where( 'barcode', 'FISHCASE' )->first() );

        /**
         * The stock code written here deliberately differs from the one the branch export uses,
         * because in the real files they disagree. Matching on the barcode is what makes the
         * ratio reach both files.
         */
        $ratios = storage_path( 'app/test-ratios-' . uniqid() . '.csv' );
        file_put_contents( $ratios, "รหัสสต็อก,บาร์โค้ด,บรรจุ\nSOME-OTHER-CODE,FISHCASE,12\n" );

        $this->runImport( [ '--ratios' => $ratios ] );

        unlink( $ratios );

        $product = Product::where( 'barcode', 'FISHCASE' )->first();

        $this->assertInstanceOf( Product::class, $product );

        $rows = $this->rowsByBarcode( $product->id );
        $units = $this->unitsOf( $product );

        $this->assertSame( 1.0, (float) $units[ 'ห่อ' ]->value );
        $this->assertSame( 12.0, (float) $units[ 'หีบ' ]->value );
        $this->assertSame( 34.0, (float) $rows[ 'FISHBAG' ]->sale_price );
        $this->assertSame( 395.0, (float) $rows[ 'FISHCASE' ]->sale_price );

        // One case in stock, counted in the case's own unit, is twelve bags on the shelf.
        $this->assertSame( 12.0, (float) $rows[ 'FISHBAG' ]->quantity );
        $this->assertSame( 0.0, (float) $rows[ 'FISHCASE' ]->quantity );
    }

    /**
     * A supplied ratio is applied, not trusted: it still has to divide evenly or the goods stay
     * out, so a typo in the ratio file cannot load a quantity nobody can sell.
     */
    public function test_a_confirmed_ratio_that_still_does_not_divide_is_refused(): void
    {
        $this->writeBranch( [
            [ 'ODD', 'ODDCASE', 'สินค้าอัตราผิด', 1, 'หีบ', 100, 120, 1 ],
        ] );

        $this->writeRegistry( [
            [ 'ODD', 'ODDPACK', 'สินค้าอัตราผิด', 4, 'แพ็ค', 40, 48 ],
            [ 'ODD', 'ODDCASE', 'สินค้าอัตราผิด', 1, 'หีบ', 100, 120 ],
        ] );

        $ratios = storage_path( 'app/test-ratios-' . uniqid() . '.csv' );
        file_put_contents( $ratios, "รหัสสต็อก,บาร์โค้ด,บรรจุ\nODD,ODDCASE,10\n" );

        $this->runImport( [ '--ratios' => $ratios ] );

        unlink( $ratios );

        $this->assertSame(
            0,
            ProductUnitQuantity::where( 'branch_id', self::BRANCH_ID )->count(),
            'Ten does not divide by four, so the goods stay out rather than carry 2.5.'
        );
    }

    public function test_goods_that_fall_out_of_the_plan_are_returned_to_a_single_unit(): void
    {
        $branch = [ 'MIX', 'MIXPACK', 'กล่องใส E-66 แพ็ค 100 ใบ', 100, 'แพ็ค', 38.75, 55, 2 ];

        // First the goods are accepted, because both levels divide evenly.
        $this->writeBranch( [ $branch ] );
        $this->writeRegistry( [
            [ 'MIX', 'MIXPACK', 'กล่องใส E-66 แพ็ค 100 ใบ', 100, 'แพ็ค', 38.75, 55 ],
            [ 'MIX', 'MIXCASE', 'กล่องใส E-66 แพ็ค 100 ใบ', 300, 'หีบ', 1162.61, 1555 ],
        ] );

        $this->runImport();

        $product = Product::where( 'barcode', 'MIXPACK' )->first();

        $this->assertTrue( (bool) $product->stock_on_base_unit );
        $this->assertCount( 2, $this->rowsByBarcode( $product->id ) );

        // Now the registry is corrected to the ratios that do not divide, and the goods drop out.
        $this->writeRegistry( [
            [ 'MIX', 'MIXPACK', 'กล่องใส E-66 แพ็ค 100 ใบ', 100, 'แพ็ค', 38.75, 55 ],
            [ 'MIX', 'MIXCASE', 'กล่องใส E-66 แพ็ค 100 ใบ', 30, 'หีบ', 1162.61, 1555 ],
        ] );

        $this->runImport();

        $product->refresh();
        $rows = $this->rowsByBarcode( $product->id );

        $this->assertFalse(
            (bool) $product->stock_on_base_unit,
            'A flag left behind would keep converting by a ratio no longer trusted.'
        );

        $this->assertSame( [ 'MIXPACK' ], array_keys( $rows ), 'One unit, its own barcode, its own price.' );
        $this->assertSame( 55.0, (float) $rows[ 'MIXPACK' ]->sale_price );
        $this->assertSame( 2.0, (float) $rows[ 'MIXPACK' ]->quantity, 'Stock comes straight from the branch file.' );

        $unit = Unit::find( $rows[ 'MIXPACK' ]->unit_id );

        $this->assertSame( 1.0, (float) $unit->value );
        $this->assertTrue( (bool) $unit->base_unit );
    }

    public function test_ratios_that_do_not_divide_evenly_are_skipped(): void
    {
        $this->writeBranch( [
            [ 'MIX', 'MIXPACK', 'กล่องใส E-66 แพ็ค 100 ใบ', 100, 'แพ็ค', 38.75, 55, 2 ],
        ] );

        $this->writeRegistry( [
            [ 'MIX', 'MIXPACK', 'กล่องใส E-66 แพ็ค 100 ใบ', 100, 'แพ็ค', 38.75, 55 ],
            [ 'MIX', 'MIXCASE', 'กล่องใส E-66 แพ็ค 100 ใบ', 30, 'หีบ', 1162.61, 1555 ],
        ] );

        $this->runImport();

        $this->assertSame(
            0,
            ProductUnitQuantity::where( 'branch_id', self::BRANCH_ID )->count(),
            'A third of a pack is not a quantity, so nothing is written.'
        );
    }

    /**
     * A spreadsheet turns a long numeric barcode into 2.00E+66 and the digits are gone. That
     * level is dropped so the rest of the goods stay usable, rather than a barcode nobody can
     * scan being loaded.
     */
    public function test_a_barcode_mangled_into_scientific_notation_is_dropped(): void
    {
        $this->writeBranch( [
            [ 'SCI', 'SCIPIECE', 'สินค้าบาร์โค้ดเสีย', 1, 'ชิ้น', 10, 12, 5 ],
        ] );

        $this->writeRegistry( [
            [ 'SCI', 'SCIPIECE', 'สินค้าบาร์โค้ดเสีย', 1, 'ชิ้น', 10, 12 ],
            [ 'SCI', '2.00E+66', 'สินค้าบาร์โค้ดเสีย', 24, 'หีบ', 240, 280 ],
            [ 'SCI', 'SCIBOX', 'สินค้าบาร์โค้ดเสีย', 12, 'กล่อง', 120, 140 ],
        ] );

        $this->runImport();

        $product = Product::where( 'barcode', 'SCIPIECE' )->first();
        $rows = $this->rowsByBarcode( $product->id );

        $this->assertSame( [ 'SCIBOX', 'SCIPIECE' ], array_keys( $rows ), 'Only the readable levels are loaded.' );
        $this->assertSame( 5.0, (float) $rows[ 'SCIPIECE' ]->quantity );
    }

    public function test_a_price_of_zero_is_loaded_but_withheld_from_sale(): void
    {
        $this->writeBranch( [
            [ 'FREE', 'FCASE', 'สินค้าไม่มีราคา', 12, 'หีบ', 0, 0, 1 ],
        ] );

        $this->writeRegistry( [
            [ 'FREE', 'FPIECE', 'สินค้าไม่มีราคา', 1, 'ชิ้น', 0, 0 ],
            [ 'FREE', 'FCASE', 'สินค้าไม่มีราคา', 12, 'หีบ', 0, 0 ],
        ] );

        $this->runImport();

        $product = Product::where( 'barcode', 'FCASE' )->first();

        $this->assertSame(
            Product::STATUS_UNAVAILABLE,
            $product->status,
            'An unpriced product reaching the till would be handed over for nothing.'
        );
    }

    /**
     * Recreates what the earlier single unit import left behind: the product exists, keyed on
     * the branch barcode, with one stock row on a unit that the rebuilt group will not contain.
     *
     * @return array{0: Product, 1: ProductUnitQuantity}
     */
    private function makeLegacySingleUnitProduct( string $barcode, string $unitName ): array
    {
        $group = new UnitGroup;
        $group->name = 'legacy-' . uniqid();
        $group->author_id = 1;
        $group->save();

        $unit = new Unit;
        $unit->name = $unitName;
        $unit->identifier = 'legacy-' . uniqid();
        $unit->group_id = $group->id;
        $unit->value = 1;
        $unit->base_unit = true;
        $unit->author_id = 1;
        $unit->save();

        $product = new Product;
        $product->name = 'สินค้าเดิม ' . $barcode;
        $product->barcode = $barcode;
        $product->sku = $barcode;
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
        $product->save();

        $row = new ProductUnitQuantity;
        $row->product_id = $product->id;
        $row->unit_id = $unit->id;
        $row->branch_id = self::BRANCH_ID;
        $row->barcode = $barcode;
        $row->quantity = 5;
        $row->sale_price = 999;
        $row->sale_price_edit = 999;
        $row->cogs = 900;
        $row->type = 'product';
        $row->visible = true;
        $row->save();

        return [ $product, $row ];
    }

    /**
     * Goods the register describes at one level only, and goods the register does not list at all,
     * are still on the branch shelf and still have to be sellable. Before --singles the importer
     * reported them as skipped and created nothing, so once the old catalogue was cleared those
     * barcodes could not be scanned.
     */
    public function test_goods_the_register_cannot_ladder_are_loaded_as_one_unit(): void
    {
        $this->writeBranch( [
            // Present in the register, but the register holds a single level.
            [ 'S1', 'SINGLE1', 'เกลือป่น 1 กก.', 1, 'ถุง', 10, 18, 7 ],
            // Absent from the register entirely: the branch row is the only description.
            [ 'S2', 'ORPHAN1', 'ขนมแบ่งขาย', 1, 'ห่อ', 20, 35, 4 ],
            // Absent from the register and sold by the carton the branch counted.
            [ 'S3', 'ORPHAN2', 'นมกล่อง ยกลัง', 48, 'หีบ', 900, 1050, 2 ],
        ] );

        $this->writeRegistry( [
            [ 'S1', 'SINGLE1', 'เกลือป่น 1 กก.', 1, 'ถุง', 10, 18 ],
        ] );

        $this->runImport( [ '--singles' => true ] );

        foreach ( [
            [ 'SINGLE1', 'ถุง', 18.0, 7.0 ],
            [ 'ORPHAN1', 'ห่อ', 35.0, 4.0 ],
            [ 'ORPHAN2', 'หีบ', 1050.0, 2.0 ],
        ] as [ $barcode, $unitName, $price, $quantity ] ) {
            $product = Product::where( 'barcode', $barcode )->first();

            $this->assertInstanceOf( Product::class, $product, "{$barcode} was not created." );
            $this->assertSame( Product::STATUS_AVAILABLE, $product->status );

            /**
             * One unit means nothing to convert, so the conversion flag has to stay off or the
             * deduction would look for a base unit that does not exist.
             */
            $this->assertFalse( (bool) $product->stock_on_base_unit );

            $rows = ProductUnitQuantity::with( 'unit' )
                ->where( 'product_id', $product->id )
                ->where( 'branch_id', self::BRANCH_ID )
                ->get();

            $this->assertCount( 1, $rows, "{$barcode} should hold exactly one unit." );
            $this->assertSame( $barcode, $rows[ 0 ]->barcode );
            $this->assertSame( $unitName, $rows[ 0 ]->unit->name );
            $this->assertEqualsWithDelta( $price, (float) $rows[ 0 ]->sale_price, 0.001 );

            /**
             * The branch counted in its own selling unit, so "2 หีบ" is two, not ninety six.
             */
            $this->assertEqualsWithDelta( $quantity, (float) $rows[ 0 ]->quantity, 0.001 );
        }
    }

    /**
     * A price of zero would hand the goods over free, so such a row is loaded and withheld rather
     * than made sellable.
     */
    public function test_a_single_unit_priced_at_zero_is_withheld(): void
    {
        $this->writeBranch( [
            [ 'S9', 'FREE1', 'ของแถมยังไม่ตั้งราคา', 1, 'ชิ้น', 12, 0, 3 ],
        ] );

        /**
         * The registry carries an unrelated goods only. An empty registry file is refused outright,
         * because in a real run that means the export failed rather than that there is nothing to
         * ladder.
         */
        $this->writeRegistry( [
            [ 'SX', 'OTHER1', 'สินค้าอื่นที่ไม่เกี่ยวข้อง', 1, 'ชิ้น', 5, 9 ],
        ] );

        $this->runImport( [ '--singles' => true ] );

        $product = Product::where( 'barcode', 'FREE1' )->first();

        $this->assertInstanceOf( Product::class, $product );
        $this->assertSame( Product::STATUS_UNAVAILABLE, $product->status );
    }

    /**
     * Retiring is deliberately not a delete: sales history points at these products and tills are
     * selling, so they are taken out of the grid, the search and the scanner while staying readable.
     */
    public function test_retiring_hides_products_the_files_do_not_mention_without_deleting_them(): void
    {
        [ $stale ] = $this->makeLegacySingleUnitProduct( 'STALE1', 'ถุง' );

        $this->writeBranch( [
            [ 'S1', 'PIECE1', 'สินค้าใหม่', 1, 'กระป๋อง', 19, 25, 6 ],
        ] );

        $this->writeRegistry( [
            [ 'S1', 'PIECE1', 'สินค้าใหม่', 1, 'กระป๋อง', 19, 25 ],
            [ 'S1', 'CARTON1', 'สินค้าใหม่', 48, 'หีบ', 950, 1070 ],
        ] );

        $this->runImport( [ '--singles' => true, '--retire-missing' => true ] );

        $stale->refresh();

        $this->assertSame( Product::STATUS_UNAVAILABLE, $stale->status );
        $this->assertFalse( (bool) $stale->searchable );

        /**
         * The row survives so receipts still render, but it no longer claims any stock at the branch.
         */
        $this->assertDatabaseHas( 'nexopos_products', [ 'id' => $stale->id ] );

        $remaining = ProductUnitQuantity::where( 'product_id', $stale->id )
            ->where( 'branch_id', self::BRANCH_ID )
            ->sum( 'quantity' );

        $this->assertEqualsWithDelta( 0.0, (float) $remaining, 0.001 );

        /**
         * The goods that are in the files must not be retired by the same sweep.
         */
        $kept = Product::where( 'barcode', 'PIECE1' )->first();

        $this->assertInstanceOf( Product::class, $kept );
        $this->assertSame( Product::STATUS_AVAILABLE, $kept->status );
    }

    /**
     * The guard that refuses to rebuild the catalogue under a cashier mid-sale counted held carts
     * across the whole company. A reservation left open at one branch therefore refused the import
     * for every other branch, and reservations outlive the sale they belonged to — two survived a
     * completed sale at สาขาท่าช้าง on the live shop, which would have blocked a main warehouse
     * import that could not have disturbed them.
     *
     * Every write in this command is scoped to --branch, so only a reservation against a row of that
     * branch is at risk.
     */
    public function test_a_cart_held_at_another_branch_does_not_block_this_import(): void
    {
        $otherBranchId = self::BRANCH_ID + 1;

        DB::table( 'nexopos_branches' )->insert( [
            'id' => $otherBranchId,
            'code' => 'TEST' . $otherBranchId,
            'name' => 'สาขาอื่น',
            'is_main' => false,
            'author_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ] );

        [ , $theirRow ] = $this->makeLegacySingleUnitProduct( 'OTHERBRANCH1', 'ถุง' );

        DB::table( 'nexopos_products_unit_quantities' )
            ->where( 'id', $theirRow->id )
            ->update( [ 'branch_id' => $otherBranchId ] );

        $this->holdCart( $theirRow->id );

        $this->writeBranch( [
            [ 'S1', 'PIECE1', 'สินค้าใหม่', 1, 'กระป๋อง', 19, 25, 6 ],
        ] );

        $this->writeRegistry( [
            [ 'S1', 'PIECE1', 'สินค้าใหม่', 1, 'กระป๋อง', 19, 25 ],
            [ 'S1', 'CARTON1', 'สินค้าใหม่', 48, 'หีบ', 950, 1070 ],
        ] );

        $this->runImport();

        $this->assertInstanceOf(
            Product::class,
            Product::where( 'barcode', 'PIECE1' )->first(),
            'A cart held at another branch refused this branch\'s import, so one abandoned till stops'
                . ' every other branch from loading its catalogue.'
        );
    }

    /**
     * The protection itself has to survive the scoping: a reservation against a row this import will
     * rewrite must still stop it.
     */
    public function test_a_cart_held_in_this_branch_still_blocks_the_import(): void
    {
        [ , $ourRow ] = $this->makeLegacySingleUnitProduct( 'OURBRANCH1', 'ถุง' );

        $this->holdCart( $ourRow->id );

        $this->writeBranch( [
            [ 'S1', 'PIECE1', 'สินค้าใหม่', 1, 'กระป๋อง', 19, 25, 6 ],
        ] );

        $this->writeRegistry( [
            [ 'S1', 'PIECE1', 'สินค้าใหม่', 1, 'กระป๋อง', 19, 25 ],
        ] );

        $this->artisan( 'rgbf:import-multiunit-products', [
            'branch-file' => $this->branchFile,
            'registry-file' => $this->registryFile,
            '--branch' => self::BRANCH_ID,
            '--author' => 1,
        ] )->assertFailed();

        $this->assertNull(
            Product::where( 'barcode', 'PIECE1' )->first(),
            'The import rewrote rows a cashier at this branch is holding a reservation against.'
        );
    }

    private function holdCart( int $unitQuantityId ): void
    {
        $row = ProductUnitQuantity::findOrFail( $unitQuantityId );

        DB::table( 'nexopos_orders_storage' )->insert( [
            'product_id' => $row->product_id,
            'unit_quantity_id' => $row->id,
            'unit_id' => $row->unit_id,
            'quantity' => 1,
            'session_identifier' => 'probe-' . uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ] );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runImport( array $options = [] ): void
    {
        $this->artisan( 'rgbf:import-multiunit-products', array_merge( [
            'branch-file' => $this->branchFile,
            'registry-file' => $this->registryFile,
            '--branch' => self::BRANCH_ID,
            '--author' => 1,
        ], $options ) )->assertSuccessful();
    }

    /**
     * @return array<string, ProductUnitQuantity>
     */
    private function rowsByBarcode( int $productId ): array
    {
        return ProductUnitQuantity::where( 'product_id', $productId )
            ->where( 'branch_id', self::BRANCH_ID )
            ->orderBy( 'barcode' )
            ->get()
            ->keyBy( 'barcode' )
            ->all();
    }

    /**
     * @return array<string, Unit>
     */
    private function unitsOf( Product $product ): array
    {
        return Unit::where( 'group_id', $product->unit_group )->get()->keyBy( 'name' )->all();
    }

    /**
     * @param list<array{0:string,1:string,2:string,3:float|int,4:string,5:float|int,6:float|int,7:float|int}> $rows
     */
    private function writeBranch( array $rows ): void
    {
        $lines = [
            'รายการสินค้า-สาขาทดสอบ,,,,,,,,,,,,,',
            'รหัสสต็อก,รหัสบาร์โค๊ดสินค้า,ชื่อ/รายละเอียดสินค้า,ปริมาณ/ขนาด,หน่วย,บรรจุ,หน่วยขาย,กลุ่มหลัก,ย่่อย,ผู้จำหน่าย,ชื่อ/บริษัท,ราคาทุน,ขาย (VAT),จำนวน',
        ];

        foreach ( $rows as $r ) {
            $lines[] = implode( ',', [
                $r[ 0 ], $r[ 1 ], '"' . $r[ 2 ] . '"', '', '', $r[ 3 ], $r[ 4 ], '1', '1', 'SP1', '"ผู้จำหน่ายทดสอบ"', $r[ 5 ], $r[ 6 ], $r[ 7 ],
            ] );
        }

        file_put_contents( $this->branchFile, implode( "\n", $lines ) . "\n" );
    }

    /**
     * @param list<array{0:string,1:string,2:string,3:float|int,4:string,5:float|int,6:float|int}> $rows
     */
    private function writeRegistry( array $rows ): void
    {
        $lines = [
            '"รายงานแสดงทะเบียนรายการสินค้าที่ขายทั้งหมด",,,,,,,,,,,,',
            'รหัสสต็อก,รหัสบาร์โค๊ดสินค้า,ชื่อ/รายละเอียดสินค้า,ปริมาณ/ขนาด,หน่วย,บรรจุ,หน่วยขาย,กลุ่มหลัก,ย่่อย,ผู้จำหน่าย,ชื่อ/บริษัท,ราคาทุน,ขาย (VAT)',
        ];

        foreach ( $rows as $r ) {
            $lines[] = implode( ',', [
                $r[ 0 ], $r[ 1 ], '"' . $r[ 2 ] . '"', '', '', $r[ 3 ], $r[ 4 ], '1', '1', 'SP1', '"ผู้จำหน่ายทดสอบ"', $r[ 5 ], $r[ 6 ],
            ] );
        }

        file_put_contents( $this->registryFile, implode( "\n", $lines ) . "\n" );
    }
}
