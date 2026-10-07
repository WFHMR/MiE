<?php

namespace Tests\Feature;

use App\Http\Controllers\InventoryUploadController;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * The template the shop hands to its staff, and the file they hand back.
 *
 * One standard file for everyone is only worth anything if the file provably loads. These tests read
 * the templates shipped in public/samples — the very bytes the download link serves — rather than a
 * copy written inside the test, so a template edited without thought fails here instead of at a
 * counter on a Monday morning.
 *
 * They also cover the mistake nothing on the page could prevent: Excel on a Thai copy of Windows
 * saves "CSV (Comma delimited)" as Windows-874, and every Thai name in such a file used to arrive as
 * unreadable bytes.
 */
class InventoryUploadTemplateTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    private const TEMPLATE = 'samples/standard-product-template.csv';

    private const EXAMPLE = 'samples/standard-product-example.csv';

    private function branchId(): int
    {
        $branch = Branch::first();

        if ( ! $branch instanceof Branch ) {
            $branch = new Branch;
            $branch->name = 'สาขาทดสอบแม่แบบ';
            $branch->code = 'TPL' . random_int( 100, 999 );
            $branch->is_main = false;
            $branch->author_id = 1;
            $branch->save();
        }

        return (int) $branch->id;
    }

    private function importContents( string $contents, string $filename = 'upload.csv' ): void
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

    public function test_both_templates_are_where_the_page_links_to_them(): void
    {
        foreach ( [ self::TEMPLATE, self::EXAMPLE ] as $file ) {
            $this->assertFileExists(
                public_path( $file ),
                sprintf( 'The upload page offers %s for download.', $file )
            );
        }
    }

    public function test_the_templates_are_utf8_with_a_byte_order_mark(): void
    {
        foreach ( [ self::TEMPLATE, self::EXAMPLE ] as $file ) {
            $contents = file_get_contents( public_path( $file ) );

            $this->assertTrue(
                str_starts_with( (string) $contents, "\xEF\xBB\xBF" ),
                sprintf( '%s needs a byte order mark, or Excel opens the Thai text as gibberish and staff correct it by hand.', $file )
            );

            $this->assertTrue(
                mb_check_encoding( (string) $contents, 'UTF-8' ),
                sprintf( '%s is not valid UTF-8.', $file )
            );
        }
    }

    /**
     * The blank template has to be recognised as the ladder layout even with no goods in it, because a
     * header the importer cannot place is the one failure a member of staff cannot diagnose.
     */
    public function test_the_blank_template_header_is_understood(): void
    {
        $this->attemptAuthenticate();

        $template = file_get_contents( public_path( self::TEMPLATE ) );

        $barcode = '8859' . random_int( 100000000, 999999999 );

        /**
         * One row is added the way a member of staff would: onto the end of the file as handed to them,
         * guidance lines and all.
         */
        $filled = rtrim( (string) $template, "\r\n" ) . "\n"
            . implode( ',', [
                'T-9001',
                'สินค้าทดสอบแม่แบบ',
                'ทดสอบ',
                'ย่อยทดสอบ',
                '10.00',
                $barcode,
                '15.00',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '7',
                '',
            ] ) . "\n";

        $this->importContents( $filled );

        $product = Product::where( 'barcode', $barcode )->first();

        $this->assertInstanceOf(
            Product::class,
            $product,
            'A row added to the blank template did not load, so the header the shop hands out is not the one the importer reads.'
        );

        $this->assertSame( 'สินค้าทดสอบแม่แบบ', $product->name, 'The Thai name did not survive the round trip.' );

        $row = ProductUnitQuantity::where( 'product_id', $product->id )
            ->where( 'branch_id', $this->branchId() )
            ->first();

        $this->assertInstanceOf( ProductUnitQuantity::class, $row );
        $this->assertSame( 15.0, (float) $row->sale_price );
        $this->assertSame( 7.0, (float) $row->quantity );
    }

    /**
     * The worked example is the file staff read to understand the format. If its own rows do not load,
     * it is teaching them something untrue.
     */
    public function test_the_worked_example_loads_every_case_it_demonstrates(): void
    {
        $this->attemptAuthenticate();

        $this->importContents( (string) file_get_contents( public_path( self::EXAMPLE ) ) );

        /**
         * Three levels: a case of four packs of twelve, counted in cases.
         */
        $milk = Product::where( 'barcode', '8850001000011' )->first();

        $this->assertInstanceOf( Product::class, $milk, 'The three level row did not load.' );
        $this->assertSame( 'นมข้นหวาน ตรามะลิ 380 ก.', $milk->name );

        $milkRows = ProductUnitQuantity::where( 'product_id', $milk->id )
            ->where( 'branch_id', $this->branchId() )
            ->get();

        $this->assertCount( 3, $milkRows, 'The three levels of the first row did not become three units.' );

        /**
         * Ten cases of four packs of twelve pieces is 480 pieces, held against the smallest unit.
         */
        $base = $milkRows->firstWhere( 'barcode', '8850001000011' );

        $this->assertInstanceOf( ProductUnitQuantity::class, $base );
        $this->assertSame( 480.0, (float) $base->quantity, 'A count given in cases was not converted into the unit stock is held on.' );

        /**
         * A named ladder: kilos and sacks of fifty.
         */
        $rice = Product::where( 'barcode', '8850005000011' )->first();

        $this->assertInstanceOf( Product::class, $rice, 'The row using its own unit names did not load.' );

        $riceRows = ProductUnitQuantity::where( 'product_id', $rice->id )
            ->where( 'branch_id', $this->branchId() )
            ->get();

        $this->assertCount( 2, $riceRows );
        $this->assertSame( 1000.0, (float) $riceRows->firstWhere( 'barcode', '8850005000011' )->quantity, 'Twenty sacks of fifty kilos is a thousand kilos.' );

        /**
         * Every row of the example has to produce goods. A row that quietly fails would teach a member
         * of staff to write something that does not work.
         */
        foreach ( [
            '8850002000014' => 'บะหมี่กึ่งสำเร็จรูป รสต้มยำ 60 ก.',
            '8850003000017' => 'น้ำปลา ตราทิพรส 700 มล.',
            '8850004000038' => 'น้ำดื่ม 1500 มล. แพ็ค 6 ขวด',
            '8850006000018' => 'น้ำอัดลม กระป๋อง 325 มล.',
            '8850007000015' => 'ผงซักฟอก ซอง 90 ก.',
            '8850008000012' => 'ไข่ไก่ เบอร์ 2 คัดขนาด',
        ] as $barcode => $name ) {
            $product = Product::where( 'barcode', $barcode )->first();

            $this->assertInstanceOf( Product::class, $product, sprintf( 'The example row for %s did not load.', $name ) );
            $this->assertSame( $name, $product->name, 'A Thai name in the worked example was altered on the way in.' );
        }
    }

    /**
     * The last column of the worked example explains each row to a reader. The importer must ignore a
     * column it does not know rather than refuse the file, or the explanations could not be there.
     */
    public function test_an_unknown_extra_column_is_ignored_rather_than_refused(): void
    {
        $this->attemptAuthenticate();

        $barcode = '8858' . random_int( 100000000, 999999999 );

        $csv = "\xEF\xBB\xBF" . implode( "\n", [
            'ชื่อสินค้า,บาร์โค้ดชิ้น,ราคาชิ้น,จำนวนรับเข้า,หมายเหตุที่ระบบไม่รู้จัก',
            'สินค้ามีคอลัมน์เกิน,' . $barcode . ',9.00,3,ข้อความอธิบายที่ไม่ควรทำให้ไฟล์เสีย',
        ] ) . "\n";

        $this->importContents( $csv );

        $this->assertInstanceOf(
            Product::class,
            Product::where( 'barcode', $barcode )->first(),
            'An extra column the importer does not recognise must not stop the file loading.'
        );
    }

    /**
     * The error nothing on the page could prevent.
     *
     * Excel on a Thai copy of Windows writes Windows-874 when asked for a plain CSV. Read as UTF-8 the
     * Thai bytes are invalid, so names, categories and unit names all arrived corrupted — and since the
     * importer keys goods by barcode, they loaded successfully with unreadable labels.
     */
    public function test_a_file_saved_in_the_thai_windows_encoding_still_loads_readable_names(): void
    {
        $this->attemptAuthenticate();

        $barcode = '8857' . random_int( 100000000, 999999999 );
        $name = 'น้ำตาลทรายขาว 1 กก.';

        $utf8 = implode( "\n", [
            'ชื่อสินค้า,กลุ่มหลัก,บาร์โค้ดชิ้น,ราคาชิ้น,จำนวนรับเข้า',
            sprintf( '%s,%s,%s,25.00,12', $name, 'เครื่องปรุง', $barcode ),
        ] ) . "\n";

        /**
         * Built with iconv because mbstring on this platform knows no Thai encoding — which is the same
         * reason the importer converts with iconv rather than mb_convert_encoding.
         */
        $windows874 = iconv( 'UTF-8', 'WINDOWS-874', $utf8 );

        $this->assertIsString( $windows874, 'The platform cannot produce the legacy encoding, so this test cannot run.' );

        $this->assertFalse(
            mb_check_encoding( $windows874, 'UTF-8' ),
            'The fixture is not actually in the legacy encoding, so this test would prove nothing.'
        );

        $this->importContents( $windows874, 'excel-thai.csv' );

        $product = Product::where( 'barcode', $barcode )->first();

        $this->assertInstanceOf(
            Product::class,
            $product,
            'A file saved by Excel on a Thai system did not load at all.'
        );

        $this->assertSame(
            $name,
            $product->name,
            'The Thai name came in corrupted, which is what happens when the file is read as UTF-8 without converting it.'
        );
    }

    public function test_a_utf8_file_is_left_exactly_as_it_is(): void
    {
        $this->attemptAuthenticate();

        $barcode = '8856' . random_int( 100000000, 999999999 );
        $name = 'ซอสพริก ตราทดสอบ 750 มล.';

        $csv = "\xEF\xBB\xBF" . implode( "\n", [
            'ชื่อสินค้า,บาร์โค้ดชิ้น,ราคาชิ้น,จำนวนรับเข้า',
            sprintf( '%s,%s,18.50,4', $name, $barcode ),
        ] ) . "\n";

        $this->importContents( $csv );

        $product = Product::where( 'barcode', $barcode )->first();

        $this->assertInstanceOf( Product::class, $product );
        $this->assertSame( $name, $product->name, 'Converting a file that was already UTF-8 damaged it.' );
    }
}
