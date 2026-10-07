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
 * The third upload layout: goods the shop breaks open and weighs out itself.
 *
 * The shopkeeper's question was whether a ten kilo sack of flour, beans or grain can be sold loose
 * at a hundred grams, two hundred, half a kilo and a kilo. The stock engine already allows it —
 * RepackagedGoodsStockTest proves the deduction — but the file staff fill in offered three fixed
 * rungs named piece, pack and case, so there was no way to state five sizes, and no way to state
 * them as the weights a scale actually shows.
 *
 * This layout numbers the size columns instead of naming them, and takes each size as a figure in
 * whatever the goods are measured in. The smallest size carrying a barcode holds the stock; the
 * others are labels that convert into it.
 *
 * The controller is exercised directly because dashboard web routes need a session the API test
 * harness does not carry.
 */
class InventoryUploadDividedTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    private const HEADER = 'รหัสสต็อก,ชื่อสินค้า,กลุ่มหลัก,ย่อย,ราคาทุน,หน่วยนับ'
        . ',บาร์โค้ด1,ชื่อขนาด1,ขนาด1,ราคา1'
        . ',บาร์โค้ด2,ชื่อขนาด2,ขนาด2,ราคา2'
        . ',บาร์โค้ด3,ชื่อขนาด3,ขนาด3,ราคา3'
        . ',บาร์โค้ด4,ชื่อขนาด4,ขนาด4,ราคา4'
        . ',บาร์โค้ด5,ชื่อขนาด5,ขนาด5,ราคา5'
        . ',จำนวนรับเข้า,หน่วยที่นับ';

    private function branchId(): int
    {
        $branch = Branch::first();

        if ( ! $branch instanceof Branch ) {
            $branch = new Branch;
            $branch->name = 'สาขาทดสอบแบ่งบรรจุ';
            $branch->code = 'DIV' . random_int( 100, 999 );
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
            [ 'ไฟล์สินค้าแบ่งบรรจุ', $header ?? self::HEADER ],
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
            [ 'csv_file' => UploadedFile::fake()->createWithContent( 'divided.csv', $csv ) ]
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
     * @return array<string, Unit>
     */
    private function unitsOf( Product $product ): array
    {
        return Unit::where( 'group_id', $product->unit_group )->get()->keyBy( 'name' )->all();
    }

    /**
     * The question as it was asked. A ten kilo sack, sold at a hundred grams, two hundred, half a
     * kilo and a kilo. Four scannable sizes over one figure of a hundred hundred-gram lots.
     */
    public function test_a_sack_sold_in_four_sizes_becomes_four_labels_over_one_figure(): void
    {
        $this->attemptAuthenticate();

        $this->import( [
            'SUG-1,น้ำตาลทรายขาว แบ่งขาย,7,1,350.00,กรัม'
                . ',8850000000011,100 กรัม,100,5.00'
                . ',8850000000028,200 กรัม,200,9.00'
                . ',8850000000035,ครึ่งโล,500,22.00'
                . ',8850000000042,1 กก.,1000,42.00'
                . ',,กระสอบ 10 กก.,10000,'
                . ',1,กระสอบ 10 กก.',
        ] );

        $product = Product::where( 'barcode', '8850000000011' )->first();

        $this->assertNotNull( $product, 'The row produced no product.' );
        $this->assertTrue( (bool) $product->stock_on_base_unit, 'The larger sizes have to be labels over one figure.' );

        $rows = $this->rowsOf( $product );

        $this->assertCount( 4, $rows, 'The sack has no barcode, so it gets no scannable row.' );

        $units = $this->unitsOf( $product );

        $this->assertSame( 1.0, (float) $units[ '100 กรัม' ]->value );
        $this->assertSame( 2.0, (float) $units[ '200 กรัม' ]->value );
        $this->assertSame( 5.0, (float) $units[ 'ครึ่งโล' ]->value, 'Half a kilo is five hundred-gram lots.' );
        $this->assertSame( 10.0, (float) $units[ '1 กก.' ]->value, 'A kilo is ten of them.' );

        $this->assertTrue( (bool) $units[ '100 กรัม' ]->base_unit );
        $this->assertFalse( (bool) $units[ '1 กก.' ]->base_unit );

        $this->assertArrayNotHasKey( 'กระสอบ 10 กก.', $units, 'A size with no barcode is not a unit the till sells.' );

        $this->assertSame( 5.0, (float) $rows[ '8850000000011' ]->sale_price );
        $this->assertSame( 9.0, (float) $rows[ '8850000000028' ]->sale_price );
        $this->assertSame( 22.0, (float) $rows[ '8850000000035' ]->sale_price );
        $this->assertSame( 42.0, (float) $rows[ '8850000000042' ]->sale_price );

        $this->assertSame(
            100.0,
            (float) $rows[ '8850000000011' ]->quantity,
            'One ten kilo sack is a hundred hundred-gram lots.'
        );

        foreach ( [ '8850000000028', '8850000000035', '8850000000042' ] as $label ) {
            $this->assertSame(
                0.0,
                (float) $rows[ $label ]->quantity,
                'Stock must not sit on a label row or the same sack could be sold twice.'
            );
        }
    }

    /**
     * Receiving stated in what the goods are measured in. The delivery note says ten thousand grams
     * and the shop never has to work out how many bags that is.
     */
    public function test_receiving_can_be_counted_in_the_measuring_unit(): void
    {
        $this->attemptAuthenticate();

        $branchId = $this->branchId();

        $this->import( [
            'BEA-1,ถั่วเขียว แบ่งขาย,7,1,300.00,กรัม'
                . ',8850000000059,100 กรัม,100,6.00'
                . ',8850000000066,ครึ่งโล,500,26.00'
                . ',,,,'
                . ',,,,'
                . ',,,,'
                . ',20000,กรัม',
        ], 'set', $branchId );

        $product = Product::where( 'barcode', '8850000000059' )->first();
        $this->assertNotNull( $product );

        $this->assertSame(
            200.0,
            (float) $this->rowsOf( $product )[ '8850000000059' ]->quantity,
            'Twenty thousand grams against a hundred gram bag is two hundred bags.'
        );
    }

    /**
     * Receiving two sacks on top of what is already there.
     */
    public function test_receiving_two_sacks_adds_to_the_figure_already_held(): void
    {
        $this->attemptAuthenticate();

        $branchId = $this->branchId();

        $row = 'RIC-1,ข้าวเหนียว แบ่งขาย,7,1,320.00,กรัม'
            . ',8850000000073,100 กรัม,100,8.00'
            . ',8850000000080,1 กก.,1000,68.00'
            . ',,กระสอบ,10000,'
            . ',,,,'
            . ',,,,';

        $this->import( [ $row . ',1,กระสอบ' ], 'set', $branchId );

        $product = Product::where( 'barcode', '8850000000073' )->first();
        $this->assertNotNull( $product );
        $this->assertSame( 100.0, (float) $this->rowsOf( $product )[ '8850000000073' ]->quantity );

        $this->import( [ $row . ',2,กระสอบ' ], 'receive', $branchId );

        $this->assertSame(
            300.0,
            (float) $this->rowsOf( $product )[ '8850000000073' ]->quantity,
            'One sack on the shelf plus two received is thirty kilos in hundred gram lots.'
        );
    }

    /**
     * Sizes that do not divide into the smallest one are refused. A hundred and fifty grams against
     * a hundred is one and a half bags, and a half bag is a thing the shop cannot sell or count.
     */
    public function test_a_size_that_does_not_divide_into_the_smallest_is_refused(): void
    {
        $this->attemptAuthenticate();

        $this->import( [
            'BAD-1,ขนาดหารไม่ลงตัว,7,1,100.00,กรัม'
                . ',8850000000097,100 กรัม,100,5.00'
                . ',8850000000103,150 กรัม,150,7.00'
                . ',,,,'
                . ',,,,'
                . ',,,,'
                . ',1,100 กรัม',
        ] );

        $this->assertNull(
            Product::where( 'barcode', '8850000000097' )->first(),
            'A size of a hundred and fifty against a hundred was accepted.'
        );
    }

    /**
     * A barcode with no size cannot be converted or counted, so the row is refused rather than
     * loaded at a ratio nobody stated.
     */
    public function test_a_barcode_with_no_size_is_refused(): void
    {
        $this->attemptAuthenticate();

        $this->import( [
            'BAD-2,ไม่ได้กรอกขนาด,7,1,100.00,กรัม'
                . ',8850000000110,100 กรัม,100,5.00'
                . ',8850000000127,ครึ่งโล,,22.00'
                . ',,,,'
                . ',,,,'
                . ',,,,'
                . ',1,100 กรัม',
        ] );

        $this->assertNull(
            Product::where( 'barcode', '8850000000110' )->first(),
            'A size column left blank beside a barcode was accepted.'
        );
    }

    /**
     * A size left unpriced is not offered for sale, because a scannable barcode at nothing is how
     * goods leave a shop for free. The base is exempt: an unpriced base withholds the product.
     */
    public function test_an_unpriced_size_is_not_offered(): void
    {
        $this->attemptAuthenticate();

        $this->import( [
            'SES-1,งาดำ แบ่งขาย,7,1,400.00,กรัม'
                . ',8850000000134,100 กรัม,100,9.00'
                . ',8850000000141,ครึ่งโล,500,0'
                . ',,,,'
                . ',,,,'
                . ',,,,'
                . ',10,100 กรัม',
        ] );

        $product = Product::where( 'barcode', '8850000000134' )->first();
        $this->assertNotNull( $product );

        $rows = $this->rowsOf( $product );

        $this->assertCount( 1, $rows, 'An unpriced size must not become a scannable row.' );
        $this->assertArrayNotHasKey( '8850000000141', $rows );
    }

    /**
     * Leaving the size name blank is allowed: the figure and what it is measured in read the way a
     * shelf label does anyway.
     */
    public function test_a_blank_size_name_is_taken_from_the_figure_and_the_measuring_unit(): void
    {
        $this->attemptAuthenticate();

        $this->import( [
            'FLO-1,แป้งสาลี แบ่งขาย,7,1,280.00,กรัม'
                . ',8850000000158,,250,15.00'
                . ',8850000000165,,1000,55.00'
                . ',,,,'
                . ',,,,'
                . ',,,,'
                . ',8,250 กรัม',
        ] );

        $product = Product::where( 'barcode', '8850000000158' )->first();
        $this->assertNotNull( $product );

        $units = $this->unitsOf( $product );

        $this->assertArrayHasKey( '250 กรัม', $units );
        $this->assertArrayHasKey( '1000 กรัม', $units );
        $this->assertSame( 4.0, (float) $units[ '1000 กรัม' ]->value, 'A kilo is four two-hundred-and-fifty gram bags.' );

        $this->assertSame( 8.0, (float) $this->rowsOf( $product )[ '8850000000158' ]->quantity );
    }

    /**
     * Uploading the same file again refreshes the goods rather than making a second copy of them.
     */
    public function test_uploading_the_same_row_twice_does_not_duplicate(): void
    {
        $this->attemptAuthenticate();

        $branchId = $this->branchId();

        $row = 'OAT-1,ข้าวโอ๊ต แบ่งขาย,7,1,260.00,กรัม'
            . ',8850000000172,100 กรัม,100,7.00'
            . ',8850000000189,ครึ่งโล,500,32.00'
            . ',,,,'
            . ',,,,'
            . ',,,,'
            . ',50,100 กรัม';

        $this->import( [ $row ], 'set', $branchId );
        $this->import( [ $row ], 'set', $branchId );

        $this->assertSame(
            1,
            Product::where( 'sku', 'OAT-1' )->count(),
            'The second upload made a second product.'
        );

        $product = Product::where( 'barcode', '8850000000172' )->first();
        $this->assertCount( 2, $this->rowsOf( $product ) );
        $this->assertSame( 50.0, (float) $this->rowsOf( $product )[ '8850000000172' ]->quantity );
    }

    /**
     * The sizes need not be written smallest first. The file is a person's working sheet and the
     * order they typed it in says nothing about which size holds the stock.
     */
    public function test_the_sizes_may_be_written_in_any_order(): void
    {
        $this->attemptAuthenticate();

        $this->import( [
            'MIL-1,นมผง แบ่งขาย,7,1,500.00,กรัม'
                . ',8850000000196,1 กก.,1000,90.00'
                . ',8850000000202,100 กรัม,100,10.00'
                . ',8850000000219,ครึ่งโล,500,46.00'
                . ',,,,'
                . ',,,,'
                . ',30,100 กรัม',
        ] );

        $product = Product::where( 'barcode', '8850000000202' )->first();

        $this->assertNotNull( $product, 'The product should be keyed on the smallest size, not the first column.' );

        $units = $this->unitsOf( $product );

        $this->assertTrue( (bool) $units[ '100 กรัม' ]->base_unit, 'The smallest size holds the stock whatever order it was typed in.' );
        $this->assertSame( 10.0, (float) $units[ '1 กก.' ]->value );

        $this->assertSame( 30.0, (float) $this->rowsOf( $product )[ '8850000000202' ]->quantity );
        $this->assertSame( 0.0, (float) $this->rowsOf( $product )[ '8850000000196' ]->quantity );
    }

    /**
     * Two sizes sharing a barcode cannot both be scanned, so the row is refused.
     */
    public function test_the_same_barcode_on_two_sizes_is_refused(): void
    {
        $this->attemptAuthenticate();

        $this->import( [
            'DUP-1,บาร์โค้ดซ้ำ,7,1,100.00,กรัม'
                . ',8850000000226,100 กรัม,100,5.00'
                . ',8850000000226,ครึ่งโล,500,22.00'
                . ',,,,'
                . ',,,,'
                . ',,,,'
                . ',1,100 กรัม',
        ] );

        $this->assertNull( Product::where( 'sku', 'DUP-1' )->first() );
    }

    /**
     * A counting size named the same as a sold size would make หน่วยที่นับ ambiguous, so it is
     * refused rather than resolved by whichever happened to be read first.
     */
    public function test_two_sizes_with_the_same_name_are_refused(): void
    {
        $this->attemptAuthenticate();

        $this->import( [
            'DUP-2,ชื่อขนาดซ้ำ,7,1,100.00,กรัม'
                . ',8850000000233,ถุง,100,5.00'
                . ',8850000000240,ถุง,500,22.00'
                . ',,,,'
                . ',,,,'
                . ',,,,'
                . ',1,ถุง',
        ] );

        $this->assertNull( Product::where( 'sku', 'DUP-2' )->first() );
    }

    /**
     * A bad row is set aside and the rest of the file still loads, because a catalogue of several
     * hundred lines must not be held up by one typo.
     */
    public function test_a_bad_row_is_skipped_and_the_good_ones_still_load(): void
    {
        $this->attemptAuthenticate();

        $this->import( [
            'MIX-1,แถวเสีย,7,1,100.00,กรัม'
                . ',8850000000257,100 กรัม,100,5.00'
                . ',8850000000264,150 กรัม,150,7.00'
                . ',,,,'
                . ',,,,'
                . ',,,,'
                . ',1,100 กรัม',
            'MIX-2,แถวดี,7,1,100.00,กรัม'
                . ',8850000000271,100 กรัม,100,5.00'
                . ',8850000000288,ครึ่งโล,500,24.00'
                . ',,,,'
                . ',,,,'
                . ',,,,'
                . ',12,100 กรัม',
        ] );

        $this->assertNull( Product::where( 'sku', 'MIX-1' )->first(), 'The bad row was loaded.' );

        $good = Product::where( 'barcode', '8850000000271' )->first();

        $this->assertNotNull( $good, 'The good row was blocked by the bad one.' );
        $this->assertSame( 12.0, (float) $this->rowsOf( $good )[ '8850000000271' ]->quantity );
    }

    /**
     * Naming a counting unit the row never declared is refused, because guessing what was meant
     * would silently multiply the delivery by the wrong figure.
     */
    public function test_counting_in_a_unit_the_row_never_declared_is_refused(): void
    {
        $this->attemptAuthenticate();

        $this->import( [
            'UNK-1,หน่วยที่นับไม่รู้จัก,7,1,100.00,กรัม'
                . ',8850000000295,100 กรัม,100,5.00'
                . ',,,,'
                . ',,,,'
                . ',,,,'
                . ',,,,'
                . ',3,ลัง',
        ] );

        /**
         * The whole row is set aside, not just the delivery figure. Loading the product and
         * silently dropping its quantity would leave the shop believing the file went in while the
         * shelf reads nothing, and the operator would find out at the next count rather than from
         * the error the page reports now.
         */
        $this->assertNull(
            Product::where( 'barcode', '8850000000295' )->first(),
            'A delivery counted in a unit the row never declared was accepted.'
        );
    }

    /**
     * One size on its own is an ordinary product. Nothing converts, so the flag stays off.
     */
    public function test_a_single_size_becomes_an_ordinary_one_unit_product(): void
    {
        $this->attemptAuthenticate();

        $this->import( [
            'ONE-1,ขนาดเดียว,7,1,100.00,กรัม'
                . ',8850000000301,ครึ่งโล,500,25.00'
                . ',,,,'
                . ',,,,'
                . ',,,,'
                . ',,,,'
                . ',9,ครึ่งโล',
        ] );

        $product = Product::where( 'barcode', '8850000000301' )->first();

        $this->assertNotNull( $product );
        $this->assertFalse( (bool) $product->stock_on_base_unit, 'With one size there is nothing to convert.' );
        $this->assertCount( 1, $this->rowsOf( $product ) );
        $this->assertSame( 9.0, (float) $this->rowsOf( $product )[ '8850000000301' ]->quantity );
    }

    /**
     * Nine sizes. The column series is numbered rather than named so that a shop selling more sizes
     * than the template ships with only has to add columns.
     */
    public function test_more_sizes_than_the_template_ships_with_still_load(): void
    {
        $this->attemptAuthenticate();

        $header = 'รหัสสต็อก,ชื่อสินค้า,ราคาทุน,หน่วยนับ';
        $line = 'MANY-1,ขนาดเยอะ,600.00,กรัม';

        $sizes = [ 100, 200, 300, 400, 500, 600, 700, 800, 900 ];

        foreach ( $sizes as $index => $size ) {
            $number = $index + 1;
            $header .= sprintf( ',บาร์โค้ด%d,ขนาด%d,ราคา%d', $number, $number, $number );
            $line .= sprintf( ',885000000%04d,%d,%d', 400 + $number, $size, $size / 10 );
        }

        $header .= ',จำนวนรับเข้า,หน่วยที่นับ';
        $line .= ',20,100 กรัม';

        $this->import( [ $line ], 'set', null, $header );

        $product = Product::where( 'barcode', '8850000000401' )->first();

        $this->assertNotNull( $product );
        $this->assertCount( 9, $this->rowsOf( $product ) );

        $units = $this->unitsOf( $product );

        $this->assertSame( 1.0, (float) $units[ '100 กรัม' ]->value );
        $this->assertSame( 9.0, (float) $units[ '900 กรัม' ]->value );
        $this->assertSame( 20.0, (float) $this->rowsOf( $product )[ '8850000000401' ]->quantity );
    }

    /**
     * Goods measured in something other than weight. The layout knows nothing about grams; it only
     * knows that a size is a number of whatever the row says it counts.
     */
    public function test_the_measuring_unit_need_not_be_a_weight(): void
    {
        $this->attemptAuthenticate();

        $this->import( [
            'EGG-1,ไข่ไก่ แบ่งขาย,7,1,120.00,ฟอง'
                . ',8850000000318,ฟอง,1,4.00'
                . ',8850000000325,แผง 10 ฟอง,10,38.00'
                . ',8850000000332,แผง 30 ฟอง,30,110.00'
                . ',,,,'
                . ',,,,'
                . ',5,แผง 30 ฟอง',
        ] );

        $product = Product::where( 'barcode', '8850000000318' )->first();

        $this->assertNotNull( $product );

        $units = $this->unitsOf( $product );

        $this->assertSame( 1.0, (float) $units[ 'ฟอง' ]->value );
        $this->assertSame( 30.0, (float) $units[ 'แผง 30 ฟอง' ]->value );

        $this->assertSame(
            150.0,
            (float) $this->rowsOf( $product )[ '8850000000318' ]->quantity,
            'Five trays of thirty is a hundred and fifty eggs.'
        );
    }
}
