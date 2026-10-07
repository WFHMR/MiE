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
 * The two files staff download for goods the shop breaks open and weighs out itself.
 *
 * These read the bytes shipped in public/samples — the very ones the download link serves — rather
 * than a copy written inside the test. A template edited without thought therefore fails here rather
 * than at a counter, and the worked example is proved to load every case it claims to demonstrate.
 *
 * The layout carries six numbered sets of four columns, so a row one comma short shifts every size
 * one column left and a price gets read as a weight. That is exactly the kind of fault a person
 * cannot see by looking, which is why the shipped example is loaded here and its figures checked.
 */
class DividedGoodsTemplateTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    private const TEMPLATE = 'samples/divided-goods-template.csv';

    private const EXAMPLE = 'samples/divided-goods-example.csv';

    private function branchId(): int
    {
        $branch = Branch::first();

        if ( ! $branch instanceof Branch ) {
            $branch = new Branch;
            $branch->name = 'สาขาทดสอบแม่แบบแบ่งบรรจุ';
            $branch->code = 'DVT' . random_int( 100, 999 );
            $branch->is_main = false;
            $branch->author_id = 1;
            $branch->save();
        }

        return (int) $branch->id;
    }

    private function importContents( string $contents, string $filename = 'divided.csv' ): void
    {
        $request = Request::create(
            '/dashboard/inventory/upload',
            'POST',
            [ 'branch_id' => $this->branchId(), 'stock_mode' => 'set' ],
            [],
            [ 'csv_file' => UploadedFile::fake()->createWithContent( $filename, $contents ) ]
        );

        app()->make( InventoryUploadController::class )->upload( $request );
    }

    private function quantityOf( string $barcode ): ?float
    {
        $row = ProductUnitQuantity::where( 'barcode', $barcode )->first();

        return $row instanceof ProductUnitQuantity ? (float) $row->quantity : null;
    }

    private function priceOf( string $barcode ): ?float
    {
        $row = ProductUnitQuantity::where( 'barcode', $barcode )->first();

        return $row instanceof ProductUnitQuantity ? (float) $row->sale_price : null;
    }

    public function test_both_files_are_where_the_page_links_to_them(): void
    {
        foreach ( [ self::TEMPLATE, self::EXAMPLE ] as $file ) {
            $this->assertFileExists(
                public_path( $file ),
                sprintf( 'The upload page offers %s for download.', $file )
            );
        }
    }

    public function test_the_files_are_utf8_with_a_byte_order_mark(): void
    {
        foreach ( [ self::TEMPLATE, self::EXAMPLE ] as $file ) {
            $contents = (string) file_get_contents( public_path( $file ) );

            $this->assertTrue(
                str_starts_with( $contents, "\xEF\xBB\xBF" ),
                sprintf( '%s needs a byte order mark, or Excel opens the Thai as gibberish.', $file )
            );

            $this->assertTrue( mb_check_encoding( $contents, 'UTF-8' ), sprintf( '%s is not valid UTF-8.', $file ) );
        }
    }

    /**
     * Every row of both files has to carry exactly as many fields as the header. This is the fault
     * the layout is most exposed to and the one a person is least able to see.
     */
    public function test_every_row_of_both_files_has_the_same_width_as_its_header(): void
    {
        foreach ( [ self::TEMPLATE, self::EXAMPLE ] as $file ) {
            $handle = fopen( public_path( $file ), 'r' );

            $this->assertNotFalse( $handle );

            $lines = [];

            while ( ( $line = fgetcsv( $handle ) ) !== false ) {
                $lines[] = $line;
            }

            fclose( $handle );

            $headerIndex = null;

            foreach ( $lines as $index => $line ) {
                if ( in_array( 'ขนาด1', array_map( 'trim', array_map( 'strval', $line ) ), true ) ) {
                    $headerIndex = $index;

                    break;
                }
            }

            $this->assertNotNull( $headerIndex, sprintf( '%s has no header row carrying ขนาด1.', $file ) );

            $width = count( $lines[ $headerIndex ] );

            /**
             * The blank template is the 32 columns of the layout. The worked example carries one more,
             * กรณีนี้สอนอะไร, which the importer ignores and the reader needs.
             */
            $expected = $file === self::EXAMPLE ? 33 : 32;

            $this->assertSame(
                $expected,
                $width,
                sprintf( '%s header is %d columns, expected %d.', $file, $width, $expected )
            );

            foreach ( array_slice( $lines, $headerIndex + 1 ) as $offset => $line ) {
                if ( count( array_filter( array_map( 'strval', $line ) ) ) === 0 ) {
                    continue;
                }

                $this->assertCount(
                    $width,
                    $line,
                    sprintf( '%s row %d has %d fields against a header of %d.', $file, $headerIndex + $offset + 2, count( $line ), $width )
                );
            }
        }
    }

    /**
     * The blank template must be recognised as the divided layout with nothing in it, because a
     * header the importer cannot place is the one failure a member of staff cannot diagnose.
     */
    public function test_the_blank_template_is_understood_once_a_row_is_added(): void
    {
        $this->attemptAuthenticate();

        $template = (string) file_get_contents( public_path( self::TEMPLATE ) );

        $small = '8858' . random_int( 100000000, 999999999 );
        $large = '8858' . random_int( 100000000, 999999999 );

        $cells = array_merge(
            [ 'TPL-DIV-1', 'ทดสอบแม่แบบเปล่า', 'ของแห้ง', 'ทดสอบ', '100', 'กรัม' ],
            [ $small, '100 กรัม', '100', '8' ],
            [ $large, 'ครึ่งโล', '500', '36' ],
            array_fill( 0, 16, '' ),
            [ '25', '100 กรัม' ]
        );

        $this->assertCount( 32, $cells, 'The row written here has to match the blank template width.' );

        $this->importContents( $template . implode( ',', $cells ) . "\n" );

        $product = Product::where( 'barcode', $small )->first();

        $this->assertNotNull( $product, 'The blank template header was not recognised.' );
        $this->assertSame( 8.0, $this->priceOf( $small ) );
        $this->assertSame( 36.0, $this->priceOf( $large ) );
        $this->assertSame( 25.0, $this->quantityOf( $small ) );
        $this->assertSame( 0.0, $this->quantityOf( $large ), 'Stock belongs on the smallest size alone.' );
    }

    /**
     * The worked example, loaded exactly as it ships. Each figure below is the arithmetic the file
     * claims to demonstrate, so a change to the example that breaks one of its own lessons fails here.
     */
    public function test_the_worked_example_loads_every_case_it_demonstrates(): void
    {
        $this->attemptAuthenticate();

        $this->importContents( (string) file_get_contents( public_path( self::EXAMPLE ) ) );

        /** The shopkeeper's question: a ten kilo sack sold at four weights. */
        $sugar = Product::where( 'barcode', '8850000100011' )->first();

        $this->assertNotNull( $sugar, 'The sugar row did not load.' );
        $this->assertTrue( (bool) $sugar->stock_on_base_unit );

        $this->assertSame(
            100.0,
            $this->quantityOf( '8850000100011' ),
            'One ten kilo sack is a hundred hundred-gram lots.'
        );

        $this->assertSame( 5.0, $this->priceOf( '8850000100011' ) );
        $this->assertSame( 42.0, $this->priceOf( '8850000100042' ) );
        $this->assertSame( 0.0, $this->quantityOf( '8850000100042' ), 'The kilo label holds no figure of its own.' );

        $sugarUnits = Unit::where( 'group_id', $sugar->unit_group )->get()->keyBy( 'name' );

        $this->assertSame( 10.0, (float) $sugarUnits[ '1 กก.' ]->value );
        $this->assertArrayNotHasKey( 'กระสอบ 10 กก.', $sugarUnits->all(), 'The sack carries no barcode, so it is not a unit the till sells.' );

        /** Counted in grams straight off the delivery note. */
        $this->assertSame(
            200.0,
            $this->quantityOf( '8850000100059' ),
            'Twenty thousand grams against a hundred gram bag is two hundred bags.'
        );

        /** Size names left blank become the figure and the measuring unit. */
        $flour = Product::where( 'barcode', '8850000100080' )->first();

        $this->assertNotNull( $flour );

        $flourUnits = Unit::where( 'group_id', $flour->unit_group )->get()->keyBy( 'name' );

        $this->assertArrayHasKey( '250 กรัม', $flourUnits->all() );
        $this->assertArrayHasKey( '1000 กรัม', $flourUnits->all() );
        $this->assertSame( 4.0, (float) $flourUnits[ '1000 กรัม' ]->value );
        $this->assertSame( 40.0, $this->quantityOf( '8850000100080' ) );

        /** Two fifteen kilo sacks of rice, sold in kilos. */
        $this->assertSame(
            30.0,
            $this->quantityOf( '8850000100103' ),
            'Two fifteen kilo sacks is thirty kilos.'
        );

        /** Eggs. The layout knows nothing about weight. */
        $this->assertSame(
            150.0,
            $this->quantityOf( '8850000100127' ),
            'Five trays of thirty is a hundred and fifty eggs.'
        );

        /** Oil, decanted from a twenty litre tin into half litres. */
        $this->assertSame(
            40.0,
            $this->quantityOf( '8850000100158' ),
            'A twenty litre tin is forty five hundred millilitre bottles.'
        );

        /** One size on its own stays an ordinary product. */
        $sesame = Product::where( 'barcode', '8850000100172' )->first();

        $this->assertNotNull( $sesame );
        $this->assertFalse( (bool) $sesame->stock_on_base_unit, 'With one size there is nothing to convert.' );
        $this->assertSame( 15.0, $this->quantityOf( '8850000100172' ) );

        /** A size priced at nothing is not offered, so a scan of it cannot give the goods away. */
        $this->assertSame( 60.0, $this->quantityOf( '8850000100189' ) );
        $this->assertNull( $this->quantityOf( '8850000100196' ), 'An unpriced size must not become a scannable row.' );
    }

    /**
     * The worked example carries eight invented products with invented barcodes. Someone learning the
     * page downloads it to see how the columns work and then uploads it, which would put fictional
     * sugar into a real catalogue, priced and sellable. The file has to say so on its first line, where
     * it cannot be missed.
     */
    public function test_the_worked_example_warns_against_uploading_itself(): void
    {
        $contents = (string) file_get_contents( public_path( self::EXAMPLE ) );

        $firstLine = strtok( $contents, "\n" );

        $this->assertStringContainsString(
            'ห้ามอัพไฟล์นี้เข้าระบบ',
            (string) $firstLine,
            'The example must warn on its first line that its products are invented.'
        );

        $this->assertStringContainsString( 'สินค้าสมมติ', $contents );
    }

    /**
     * Each row of the example explains itself, in the file, beside the data. A separate document is not
     * open when the spreadsheet is.
     */
    public function test_every_row_of_the_worked_example_explains_what_it_demonstrates(): void
    {
        $handle = fopen( public_path( self::EXAMPLE ), 'r' );

        $this->assertNotFalse( $handle );

        $lines = [];

        while ( ( $line = fgetcsv( $handle ) ) !== false ) {
            $lines[] = $line;
        }

        fclose( $handle );

        $headerIndex = null;

        foreach ( $lines as $index => $line ) {
            if ( in_array( 'กรณีนี้สอนอะไร', array_map( 'trim', array_map( 'strval', $line ) ), true ) ) {
                $headerIndex = $index;

                break;
            }
        }

        $this->assertNotNull( $headerIndex, 'The example has no column explaining its rows.' );

        $lessonColumn = count( $lines[ $headerIndex ] ) - 1;

        $rows = array_slice( $lines, $headerIndex + 1 );

        $this->assertCount( 8, $rows, 'The example is meant to carry eight worked cases.' );

        foreach ( $rows as $offset => $line ) {
            $this->assertNotSame(
                '',
                trim( (string) ( $line[ $lessonColumn ] ?? '' ) ),
                sprintf( 'Row %d of the example does not say what it demonstrates.', $headerIndex + $offset + 2 )
            );
        }
    }

    /**
     * Excel on a Thai copy of Windows saves "CSV (Comma delimited)" as Windows-874. The page converts
     * it, and the sizes have to survive the conversion along with the names.
     */
    public function test_the_example_still_loads_when_saved_as_windows_874(): void
    {
        $this->attemptAuthenticate();

        $utf8 = (string) file_get_contents( public_path( self::EXAMPLE ) );

        $legacy = iconv( 'UTF-8', 'CP874//IGNORE', substr( $utf8, 3 ) );

        $this->assertNotFalse( $legacy, 'The example could not be written as Windows-874.' );

        $this->importContents( (string) $legacy, 'thai-excel.csv' );

        $sugar = Product::where( 'barcode', '8850000100011' )->first();

        $this->assertNotNull( $sugar, 'A Windows-874 file did not load.' );

        $this->assertStringContainsString(
            'น้ำตาล',
            (string) $sugar->name,
            'The Thai name did not survive the conversion.'
        );

        $this->assertSame( 100.0, $this->quantityOf( '8850000100011' ) );
    }

    /**
     * A shop that adds its own note column must not have the file refused. Anything the layout does
     * not recognise is passed over.
     */
    public function test_a_column_the_layout_does_not_know_is_ignored(): void
    {
        $this->attemptAuthenticate();

        $barcode = '8857' . random_int( 100000000, 999999999 );

        $header = 'รหัสสต็อก,ชื่อสินค้า,ราคาทุน,หน่วยนับ,บาร์โค้ด1,ชื่อขนาด1,ขนาด1,ราคา1,หมายเหตุของร้าน,จำนวนรับเข้า,หน่วยที่นับ';
        $row = sprintf( 'EXTRA-1,ทดสอบคอลัมน์เกิน,50,กรัม,%s,100 กรัม,100,9,ของฝากจากผู้แทน,7,100 กรัม', $barcode );

        $this->importContents( "ไฟล์ทดสอบ\n" . $header . "\n" . $row . "\n" );

        $this->assertNotNull( Product::where( 'barcode', $barcode )->first(), 'An unknown column stopped the file loading.' );
        $this->assertSame( 7.0, $this->quantityOf( $barcode ) );
    }
}
