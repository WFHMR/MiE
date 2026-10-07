<?php

namespace Tests\Feature;

use App\Http\Controllers\InventoryUploadController;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Unit;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * The second upload layout: one row carries a product's whole unit ladder.
 *
 * The branch export describes one unit per row, so goods sold as ชิ้น, แพ็ค and หีบ needed three
 * rows plus a second register file to say how the three relate. This layout states the
 * relationship in the row: a case holds so many packs, a pack holds so many pieces. A level left
 * blank is a unit the shop does not sell separately.
 *
 * The controller is exercised directly because dashboard web routes need a session the API test
 * harness does not carry.
 */
class InventoryUploadLadderTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    private const HEADER = 'รหัสสต็อก,ชื่อสินค้า,กลุ่มหลัก,ย่อย,ราคาทุน,บาร์โค้ดชิ้น,ราคาชิ้น,บาร์โค้ดแพ็ค,ชิ้นต่อแพ็ค,ราคาแพ็ค,บาร์โค้ดหีบ,แพ็คต่อหีบ,ราคาหีบ,จำนวนรับเข้า,หน่วยที่นับ';

    private function branchId(): int
    {
        $branch = Branch::first();

        if ( ! $branch instanceof Branch ) {
            $branch = new Branch;
            $branch->name = 'สาขาทดสอบชั้นหน่วย';
            $branch->code = 'LAD' . random_int( 100, 999 );
            $branch->is_main = false;
            $branch->author_id = 1;
            $branch->save();
        }

        return (int) $branch->id;
    }

    /**
     * @param list<string> $dataLines
     */
    private function import( array $dataLines, string $mode = 'set', ?int $branchId = null, ?string $header = null ): void
    {
        $csv = implode( "\n", array_merge(
            [ 'ไฟล์ตัวอย่างชั้นหน่วย,,,,,,,,,,,,,,', $header ?? self::HEADER ],
            $dataLines
        ) ) . "\n";

        $request = Request::create(
            '/dashboard/inventory/upload',
            'POST',
            [
                'branch_id' => $branchId ?? $this->branchId(),
                'stock_mode' => $mode,
            ],
            [],
            [ 'csv_file' => UploadedFile::fake()->createWithContent( 'ladder.csv', $csv ) ]
        );

        app()->make( InventoryUploadController::class )->upload( $request );
    }

    /**
     * @return array<string, ProductUnitQuantity>
     */
    private function rowsOf( Product $product ): array
    {
        return ProductUnitQuantity::where( 'product_id', $product->id )
            ->get()
            ->keyBy( fn ( ProductUnitQuantity $row ): string => (string) $row->barcode )
            ->all();
    }

    /**
     * The full case: pieces, a pack of twelve, a case of four packs. The case is therefore forty
     * eight pieces, and the stock sits on the piece.
     */
    public function test_a_full_ladder_becomes_three_units_over_one_stock_figure(): void
    {
        $this->attemptAuthenticate();

        $this->import( [
            'LAD-1,ทดสอบชั้นหน่วยครบ,7,1,80.00,8991000000011,12.00,8991000000028,12,130.00,8991000000035,4,500.00,10,ชิ้น',
        ] );

        $product = Product::where( 'barcode', '8991000000011' )->first();

        $this->assertNotNull( $product, 'The row produced no product.' );
        $this->assertTrue( (bool) $product->stock_on_base_unit, 'The larger units have to be labels over one figure.' );

        $rows = $this->rowsOf( $product );

        $this->assertCount( 3, $rows );

        $units = Unit::where( 'group_id', $product->unit_group )->get()->keyBy( 'name' );

        $this->assertSame( 1.0, (float) $units[ 'ชิ้น' ]->value );
        $this->assertSame( 12.0, (float) $units[ 'แพ็ค' ]->value );
        $this->assertSame( 48.0, (float) $units[ 'หีบ' ]->value, 'Four packs of twelve is forty eight pieces.' );

        $this->assertTrue( (bool) $units[ 'ชิ้น' ]->base_unit );
        $this->assertFalse( (bool) $units[ 'แพ็ค' ]->base_unit );

        $this->assertSame( 12.0, (float) $rows[ '8991000000011' ]->sale_price );
        $this->assertSame( 130.0, (float) $rows[ '8991000000028' ]->sale_price );
        $this->assertSame( 500.0, (float) $rows[ '8991000000035' ]->sale_price );

        $this->assertSame( 10.0, (float) $rows[ '8991000000011' ]->quantity );
        $this->assertSame( 0.0, (float) $rows[ '8991000000028' ]->quantity, 'Stock must not sit on a label row.' );
        $this->assertSame( 0.0, (float) $rows[ '8991000000035' ]->quantity );
    }

    /**
     * A blank level is a unit the shop does not sell separately, so no row is made for it.
     */
    public function test_a_blank_level_is_not_created(): void
    {
        $this->attemptAuthenticate();

        $this->import( [
            'LAD-2,ทดสอบไม่ขายแยกแพ็ค,7,1,80.00,8991000000042,15.00,,,,8991000000059,24,320.00,5,ชิ้น',
        ] );

        $product = Product::where( 'barcode', '8991000000042' )->first();

        $this->assertNotNull( $product );

        $rows = $this->rowsOf( $product );

        $this->assertCount( 2, $rows, 'Only the two levels the row filled in should exist.' );
        $this->assertArrayHasKey( '8991000000042', $rows );
        $this->assertArrayHasKey( '8991000000059', $rows );

        $units = Unit::where( 'group_id', $product->unit_group )->get()->keyBy( 'name' );

        $this->assertArrayNotHasKey( 'แพ็ค', $units->all() );
        $this->assertSame(
            24.0,
            (float) $units[ 'หีบ' ]->value,
            'With no pack in between, the case quantity counts pieces.'
        );
    }

    /**
     * Goods sold only by the case, which is how a wholesaler lists some lines.
     */
    public function test_a_single_level_becomes_an_ordinary_one_unit_product(): void
    {
        $this->attemptAuthenticate();

        $this->import( [
            'LAD-3,ทดสอบขายเป็นหีบเท่านั้น,7,1,900.00,,,,,,8991000000066,1,1200.00,3,หีบ',
        ] );

        $product = Product::where( 'barcode', '8991000000066' )->first();

        $this->assertNotNull( $product );
        $this->assertFalse( (bool) $product->stock_on_base_unit, 'One unit needs no conversion.' );

        $rows = $this->rowsOf( $product );

        $this->assertCount( 1, $rows );
        $this->assertSame( 1200.0, (float) $rows[ '8991000000066' ]->sale_price );
        $this->assertSame( 3.0, (float) $rows[ '8991000000066' ]->quantity );
    }

    /**
     * Receiving is stated in whichever unit the delivery was counted in, and lands on the base.
     */
    public function test_receiving_in_cases_converts_onto_the_base_unit(): void
    {
        $this->attemptAuthenticate();

        $branchId = $this->branchId();

        $this->import( [
            'LAD-4,ทดสอบรับเป็นหีบ,7,1,80.00,8991000000073,12.00,8991000000080,12,130.00,8991000000097,4,500.00,24,ชิ้น',
        ], 'set', $branchId );

        $product = Product::where( 'barcode', '8991000000073' )->first();
        $this->assertNotNull( $product );
        $this->assertSame( 24.0, (float) $this->rowsOf( $product )[ '8991000000073' ]->quantity );

        /**
         * Two cases received. A case is forty eight pieces, so ninety six pieces arrive on top of
         * the twenty four already there.
         */
        $this->import( [
            'LAD-4,ทดสอบรับเป็นหีบ,7,1,80.00,8991000000073,12.00,8991000000080,12,130.00,8991000000097,4,500.00,2,หีบ',
        ], 'receive', $branchId );

        $this->assertSame(
            120.0,
            (float) $this->rowsOf( $product )[ '8991000000073' ]->quantity,
            'Twenty four plus two cases of forty eight is a hundred and twenty pieces.'
        );
    }

    /**
     * A larger unit with a barcode but no conversion figure cannot be priced against the stock,
     * so the row is refused rather than guessed at.
     */
    public function test_a_level_with_no_conversion_figure_is_refused(): void
    {
        $this->attemptAuthenticate();

        $this->import( [
            'LAD-5,ทดสอบไม่บอกอัตรา,7,1,80.00,8991000000103,12.00,8991000000110,,130.00,,,,5,ชิ้น',
        ] );

        $this->assertNull(
            Product::where( 'barcode', '8991000000103' )->first(),
            'A pack with no pieces-per-pack figure was accepted.'
        );
    }

    /**
     * An unpriced larger unit gets no row: a scannable barcode at nothing hands the goods over.
     */
    public function test_an_unpriced_larger_unit_is_not_offered(): void
    {
        $this->attemptAuthenticate();

        $this->import( [
            'LAD-6,ทดสอบหีบไม่มีราคา,7,1,80.00,8991000000127,12.00,,,,8991000000134,24,0.00,5,ชิ้น',
        ] );

        $product = Product::where( 'barcode', '8991000000127' )->first();

        $this->assertNotNull( $product );

        $rows = $this->rowsOf( $product );

        $this->assertArrayHasKey( '8991000000127', $rows );
        $this->assertArrayNotHasKey( '8991000000134', $rows, 'An unpriced case must not be scannable.' );
    }

    /**
     * Uploading the same row twice updates the goods rather than making a second copy.
     */
    public function test_uploading_the_same_row_twice_does_not_duplicate(): void
    {
        $this->attemptAuthenticate();

        $branchId = $this->branchId();

        $line = 'LAD-7,ทดสอบอัพซ้ำ,7,1,80.00,8991000000141,12.00,8991000000158,12,130.00,,,,5,ชิ้น';

        $this->import( [ $line ], 'set', $branchId );
        $this->import( [ $line ], 'set', $branchId );

        $this->assertSame(
            1,
            Product::where( 'barcode', '8991000000141' )->count(),
            'The second upload created a second product.'
        );

        $product = Product::where( 'barcode', '8991000000141' )->first();

        $this->assertCount( 2, $this->rowsOf( $product ) );
    }

    /**
     * Dropping a level on a later upload takes its row away, so a barcode the shop stopped
     * selling separately stops scanning.
     */
    public function test_dropping_a_level_removes_its_row(): void
    {
        $this->attemptAuthenticate();

        $branchId = $this->branchId();

        $this->import( [
            'LAD-8,ทดสอบเลิกขายแพ็ค,7,1,80.00,8991000000165,12.00,8991000000172,12,130.00,,,,5,ชิ้น',
        ], 'set', $branchId );

        $product = Product::where( 'barcode', '8991000000165' )->first();
        $this->assertNotNull( $product );
        $this->assertCount( 2, $this->rowsOf( $product ) );

        $this->import( [
            'LAD-8,ทดสอบเลิกขายแพ็ค,7,1,80.00,8991000000165,12.00,,,,,,,5,ชิ้น',
        ], 'set', $branchId );

        $rows = $this->rowsOf( $product );

        $this->assertCount( 1, $rows );
        $this->assertArrayNotHasKey( '8991000000172', $rows );
    }

    /**
     * One row the file got wrong must not stop the rest. On a catalogue of several hundred lines
     * a single typo would otherwise keep every other product out of the shop.
     */
    public function test_a_bad_row_is_skipped_and_the_good_ones_still_load(): void
    {
        $this->attemptAuthenticate();

        $this->import( [
            'LAD-9,ทดสอบแถวดีก่อน,7,1,80.00,8991000000196,12.00,,,,,,,4,ชิ้น',
            'LAD-10,ทดสอบแถวเสีย,7,1,80.00,8991000000202,12.00,8991000000219,,130.00,,,,4,ชิ้น',
            'LAD-11,ทดสอบแถวดีหลัง,7,1,80.00,8991000000226,18.00,,,,,,,6,ชิ้น',
        ] );

        $this->assertNotNull(
            Product::where( 'barcode', '8991000000196' )->first(),
            'The row before the broken one did not load.'
        );

        $this->assertNotNull(
            Product::where( 'barcode', '8991000000226' )->first(),
            'The row after the broken one did not load.'
        );

        $this->assertNull(
            Product::where( 'barcode', '8991000000202' )->first(),
            'The broken row was loaded anyway.'
        );
    }

    /**
     * A wholesaler receives in cases and sells in packs. The case is not on sale, so it gets no
     * price and no row, but the delivery was still counted in cases — so it has to be usable as
     * the counting unit, or the storeman is back to multiplying by hand.
     */
    public function test_stock_can_be_counted_in_a_unit_that_is_not_sold(): void
    {
        $this->attemptAuthenticate();

        $this->import( [
            'LAD-12,ทดสอบนับเป็นหีบแต่ไม่ขายหีบ,7,1,80.00,8991000000233,12.00,,,,8991000000240,24,,2,หีบ',
        ] );

        $product = Product::where( 'barcode', '8991000000233' )->first();

        $this->assertNotNull( $product );

        $rows = $this->rowsOf( $product );

        $this->assertArrayNotHasKey( '8991000000240', $rows, 'An unpriced case must not be scannable.' );

        $this->assertSame(
            48.0,
            (float) $rows[ '8991000000233' ]->quantity,
            'Two cases of twenty four had to land as forty eight pieces.'
        );
    }

    /**
     * Not every ladder is ชิ้น/แพ็ค/หีบ. Rice comes by the กระสอบ and drinks by the ลัง, so the
     * row may name its own units without needing another column layout.
     */
    public function test_unit_names_can_be_renamed_per_row(): void
    {
        $this->attemptAuthenticate();

        $header = 'รหัสสต็อก,ชื่อสินค้า,กลุ่มหลัก,ย่อย,ราคาทุน,บาร์โค้ดชิ้น,ราคาชิ้น,ชื่อหน่วยเล็ก,'
            . 'บาร์โค้ดแพ็ค,ชิ้นต่อแพ็ค,ราคาแพ็ค,ชื่อหน่วยกลาง,บาร์โค้ดหีบ,แพ็คต่อหีบ,ราคาหีบ,ชื่อหน่วยใหญ่,'
            . 'จำนวนรับเข้า,หน่วยที่นับ';

        $this->import( [
            'LAD-13,ทดสอบข้าวสาร,7,1,32.00,8991000000257,38.00,กก.,,,,,8991000000264,50,1790.00,กระสอบ,20,กระสอบ',
        ], 'set', null, $header );

        $product = Product::where( 'barcode', '8991000000257' )->first();

        $this->assertNotNull( $product );

        $units = Unit::where( 'group_id', $product->unit_group )->get()->keyBy( 'name' );

        $this->assertArrayHasKey( 'กก.', $units->all(), 'The smallest unit kept the default name.' );
        $this->assertArrayHasKey( 'กระสอบ', $units->all(), 'The largest unit kept the default name.' );
        $this->assertSame( 50.0, (float) $units[ 'กระสอบ' ]->value );

        $this->assertSame(
            1000.0,
            (float) $this->rowsOf( $product )[ '8991000000257' ]->quantity,
            'Twenty sacks of fifty kilos is a thousand kilos.'
        );
    }

    /**
     * The template offered for download on the upload page is loaded here as it ships, byte for
     * byte. A sample file the shop cannot actually upload teaches the wrong layout, and it is the
     * one file every new member of staff will copy.
     */
    public function test_the_downloadable_template_loads_as_it_ships(): void
    {
        $this->attemptAuthenticate();

        $path = public_path( 'samples/sample-unit-ladder.csv' );

        $this->assertFileExists( $path, 'The upload page links to a template that is not there.' );

        $request = Request::create(
            '/dashboard/inventory/upload',
            'POST',
            [ 'branch_id' => $this->branchId(), 'stock_mode' => 'set' ],
            [],
            [ 'csv_file' => UploadedFile::fake()->createWithContent( 'sample.csv', (string) file_get_contents( $path ) ) ]
        );

        app()->make( InventoryUploadController::class )->upload( $request );

        /**
         * Every row of the template has to land, including the four that leave a level blank.
         */
        foreach ( [ '8850001000011', '8850002000014', '8850003000017', '8850004000038', '8850005000011', '8850006000018', '8850007000015', '8850008000012' ] as $barcode ) {
            $this->assertNotNull(
                Product::where( 'barcode', $barcode )->first(),
                sprintf( 'Template row %s did not load.', $barcode )
            );
        }

        /**
         * A pack of twelve inside a case of four packs is forty eight pieces, and ten cases
         * received is four hundred and eighty of them.
         */
        $full = Product::where( 'barcode', '8850001000011' )->first();
        $units = Unit::where( 'group_id', $full->unit_group )->get()->keyBy( 'name' );

        $this->assertSame( 12.0, (float) $units[ 'แพ็ค' ]->value );
        $this->assertSame( 48.0, (float) $units[ 'หีบ' ]->value );
        $this->assertSame( 480.0, (float) $this->rowsOf( $full )[ '8850001000011' ]->quantity );

        /**
         * With no pack level filled in, the case figure counts pieces: twelve, not a hundred
         * and forty four.
         */
        $noPack = Product::where( 'barcode', '8850003000017' )->first();
        $noPackUnits = Unit::where( 'group_id', $noPack->unit_group )->get()->keyBy( 'name' );

        $this->assertSame( 12.0, (float) $noPackUnits[ 'หีบ' ]->value );
        $this->assertSame( 96.0, (float) $this->rowsOf( $noPack )[ '8850003000017' ]->quantity );

        /**
         * The renamed ladder: kilos and sacks of fifty.
         */
        $rice = Product::where( 'barcode', '8850005000011' )->first();
        $riceUnits = Unit::where( 'group_id', $rice->unit_group )->get()->keyBy( 'name' );

        $this->assertArrayHasKey( 'กก.', $riceUnits->all() );
        $this->assertSame( 50.0, (float) $riceUnits[ 'กระสอบ' ]->value );
        $this->assertSame( 1000.0, (float) $this->rowsOf( $rice )[ '8850005000011' ]->quantity );

        /**
         * Received by the case, sold by the pack: the case converts the delivery but never
         * becomes scannable, because the template leaves its price blank.
         */
        $received = Product::where( 'barcode', '8850007000015' )->first();
        $receivedRows = $this->rowsOf( $received );

        $this->assertArrayNotHasKey( '8850007000039', $receivedRows, 'An unpriced case must not be scannable.' );
        $this->assertSame( 288.0, (float) $receivedRows[ '8850007000015' ]->quantity );

        /**
         * Sold only by the ลัง, so it is an ordinary one unit product counted in ลัง.
         */
        $caseOnly = Product::where( 'barcode', '8850004000038' )->first();

        $this->assertFalse( (bool) $caseOnly->stock_on_base_unit );
        $this->assertSame( 60.0, (float) $this->rowsOf( $caseOnly )[ '8850004000038' ]->quantity );
        $this->assertSame(
            'ลัง',
            (string) Unit::where( 'group_id', $caseOnly->unit_group )->first()->name
        );
    }

    /**
     * Deleting the guidance lines off the template leaves Excel's byte order mark sitting on the
     * header itself, which is the one place it stops a column name from matching.
     */
    public function test_a_byte_order_mark_on_the_header_does_not_break_it(): void
    {
        $this->attemptAuthenticate();

        $csv = "\xEF\xBB\xBF" . self::HEADER . "\n"
            . 'LAD-14,ทดสอบมี BOM,7,1,80.00,8991000000271,12.00,,,,,,,5,ชิ้น' . "\n";

        $request = Request::create(
            '/dashboard/inventory/upload',
            'POST',
            [ 'branch_id' => $this->branchId(), 'stock_mode' => 'set' ],
            [],
            [ 'csv_file' => UploadedFile::fake()->createWithContent( 'bom.csv', $csv ) ]
        );

        app()->make( InventoryUploadController::class )->upload( $request );

        $product = Product::where( 'barcode', '8991000000271' )->first();

        $this->assertNotNull( $product, 'A byte order mark on the header row stopped the file loading.' );
        $this->assertSame( 'LAD-14', (string) $product->sku, 'The first column was read with the mark still on it.' );
    }

    /**
     * The one-unit-per-row export has to keep loading exactly as before, so the two layouts can
     * live on the same page.
     */
    public function test_the_original_layout_still_loads(): void
    {
        $this->attemptAuthenticate();

        $csv = implode( "\n", [
            'รายการสินค้า-สาขาทดสอบ,,,,,,,,,,,,,',
            'รหัสสต็อก,รหัสบาร์โค๊ดสินค้า,ชื่อ/รายละเอียดสินค้า,ปริมาณ/ขนาด,หน่วย,บรรจุ,หน่วยขาย,กลุ่มหลัก,ย่่อย,ผู้จำหน่าย,ชื่อ/บริษัท,ราคาทุน,ขาย (VAT),จำนวน',
            'OLD-1,8991000000189,"ทดสอบรูปแบบเดิม",155,กรัม,1,ชิ้น,7,1,SP1,"x",10.00,25.00,7',
        ] ) . "\n";

        $request = Request::create(
            '/dashboard/inventory/upload',
            'POST',
            [ 'branch_id' => $this->branchId(), 'stock_mode' => 'set' ],
            [],
            [ 'csv_file' => UploadedFile::fake()->createWithContent( 'old.csv', $csv ) ]
        );

        app()->make( InventoryUploadController::class )->upload( $request );

        $product = Product::where( 'barcode', '8991000000189' )->first();

        $this->assertNotNull( $product, 'The original layout stopped working.' );
        $this->assertSame( 25.0, (float) $this->rowsOf( $product )[ '8991000000189' ]->sale_price );
        $this->assertSame( 7.0, (float) $this->rowsOf( $product )[ '8991000000189' ]->quantity );
    }
}
