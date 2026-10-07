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
 * Every sample file the upload page offers, loaded as it ships.
 *
 * The page hands out five files. Four were covered; sample-branch-export.csv was offered for download
 * with nothing proving it loads at all, which is the worst of the set to leave unchecked because it is
 * the fallback a shop reaches for when its own export will not go in.
 *
 * A sample that does not load teaches the wrong layout, and it is the one file every new member of
 * staff copies. So this walks the shipped bytes of each file through the real controller and checks
 * the figures, rather than checking the files exist.
 *
 * The per-file detail lives in InventoryUploadTemplateTest and DividedGoodsTemplateTest. This is the
 * inventory: it fails if a file the page links to is missing, unreadable, or loads nothing.
 */
class SampleCsvFilesTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    /**
     * Every file the upload page offers for download, as it appears in the asset() call.
     *
     * @var list<string>
     */
    private const OFFERED = [
        'samples/standard-product-template.csv',
        'samples/standard-product-example.csv',
        'samples/divided-goods-template.csv',
        'samples/divided-goods-example.csv',
        'samples/sample-branch-export.csv',
    ];

    private function branchId(): int
    {
        $branch = Branch::first();

        if ( ! $branch instanceof Branch ) {
            $branch = new Branch;
            $branch->name = 'สาขาทดสอบไฟล์ตัวอย่าง';
            $branch->code = 'SMP' . random_int( 100, 999 );
            $branch->is_main = false;
            $branch->author_id = 1;
            $branch->save();
        }

        return (int) $branch->id;
    }

    private function importFile( string $relativePath, string $mode = 'set' ): void
    {
        $request = Request::create(
            '/dashboard/inventory/upload',
            'POST',
            [ 'branch_id' => $this->branchId(), 'stock_mode' => $mode ],
            [],
            [ 'csv_file' => UploadedFile::fake()->createWithContent(
                'sample.csv',
                (string) file_get_contents( public_path( $relativePath ) )
            ) ]
        );

        app()->make( InventoryUploadController::class )->upload( $request );
    }

    private function rowFor( string $barcode ): ?ProductUnitQuantity
    {
        return ProductUnitQuantity::where( 'barcode', $barcode )->first();
    }

    /**
     * The page must not offer a file that is not there. A dead download link is reported by staff as
     * "the system is broken" and takes an afternoon to trace.
     */
    public function test_every_file_the_page_offers_exists_and_is_readable(): void
    {
        foreach ( self::OFFERED as $file ) {
            $path = public_path( $file );

            $this->assertFileExists( $path, sprintf( 'The upload page links to %s.', $file ) );
            $this->assertFileIsReadable( $path );

            $contents = (string) file_get_contents( $path );

            $this->assertNotSame( '', trim( $contents ), sprintf( '%s is empty.', $file ) );

            $this->assertTrue(
                mb_check_encoding( $contents, 'UTF-8' ),
                sprintf( '%s is not valid UTF-8, so Excel will show the Thai as gibberish.', $file )
            );
        }
    }

    /**
     * The link in the Blade view and the file on disk have to be the same string. A rename that
     * updates one and not the other is how a download link dies quietly.
     */
    public function test_the_page_and_the_disk_agree_on_the_file_names(): void
    {
        $view = (string) file_get_contents( resource_path( 'views/pages/dashboard/inventory/upload.blade.php' ) );

        foreach ( self::OFFERED as $file ) {
            $this->assertStringContainsString(
                $file,
                $view,
                sprintf( '%s sits in public/samples but the page never offers it.', $file )
            );
        }

        /**
         * The reverse direction: anything the page offers has to be in the list above, or it goes
         * untested exactly the way sample-branch-export.csv did.
         */
        preg_match_all( "/asset\(\s*'(samples\/[^']+)'\s*\)/", $view, $matches );

        foreach ( array_unique( $matches[ 1 ] ) as $offered ) {
            $this->assertContains(
                $offered,
                self::OFFERED,
                sprintf( 'The page offers %s but this test does not cover it.', $offered )
            );
        }
    }

    /**
     * The fallback layout, loaded as it ships. One row is one unit, so goods the branch stocks in two
     * units arrive as two products — which is what the page tells the reader, and what this pins.
     */
    public function test_the_branch_export_sample_loads_every_row(): void
    {
        $this->attemptAuthenticate();

        $this->importFile( 'samples/sample-branch-export.csv' );

        $expected = [
            '8850111000017' => [ 'unit' => 'ขวด', 'price' => 42.0, 'quantity' => 24.0 ],
            '8850111000024' => [ 'unit' => 'ขวด', 'price' => 58.0, 'quantity' => 12.0 ],
            '8850111000031' => [ 'unit' => 'ถุง', 'price' => 110.0, 'quantity' => 6.0 ],
            '8850111000048' => [ 'unit' => 'กล่อง', 'price' => 70.0, 'quantity' => 18.0 ],
            '18850111000045' => [ 'unit' => 'หีบ', 'price' => 820.0, 'quantity' => 2.0 ],
            '8850111000055' => [ 'unit' => 'ถุง', 'price' => 0.0, 'quantity' => 5.0 ],
        ];

        foreach ( $expected as $barcode => $wanted ) {
            $row = $this->rowFor( (string) $barcode );

            $this->assertNotNull( $row, sprintf( 'Row %s of the branch export sample did not load.', $barcode ) );

            $this->assertSame(
                $wanted[ 'price' ],
                (float) $row->sale_price,
                sprintf( 'Row %s loaded at the wrong price.', $barcode )
            );

            $this->assertSame(
                $wanted[ 'quantity' ],
                (float) $row->quantity,
                sprintf( 'Row %s loaded at the wrong stock figure.', $barcode )
            );

            $unit = Unit::find( $row->unit_id );

            $this->assertNotNull( $unit );

            $this->assertSame(
                $wanted[ 'unit' ],
                (string) $unit->name,
                sprintf( 'Row %s took its unit from the wrong column.', $barcode )
            );
        }
    }

    /**
     * The unpriced row in that sample is loaded and withheld. A scannable barcode at nothing is how
     * goods leave a shop for free, so the sample has to demonstrate the safe behaviour rather than
     * quietly publish the product.
     */
    public function test_the_unpriced_row_of_the_branch_export_sample_is_withheld(): void
    {
        $this->attemptAuthenticate();

        $this->importFile( 'samples/sample-branch-export.csv' );

        $row = $this->rowFor( '8850111000055' );

        $this->assertNotNull( $row );

        $product = Product::find( $row->product_id );

        $this->assertNotNull( $product );

        $this->assertSame(
            Product::STATUS_UNAVAILABLE,
            (string) $product->status,
            'A product priced at nothing was published for sale.'
        );
    }

    /**
     * Two rows of that sample describe the same goods at two units. The layout makes two products of
     * them, and the page says so. This pins it, because a reader who expects one product with two
     * units would otherwise find the difference only after uploading their whole catalogue.
     */
    public function test_the_branch_export_sample_makes_one_product_per_row(): void
    {
        $this->attemptAuthenticate();

        $this->importFile( 'samples/sample-branch-export.csv' );

        $box = $this->rowFor( '8850111000048' );
        $case = $this->rowFor( '18850111000045' );

        $this->assertNotNull( $box );
        $this->assertNotNull( $case );

        $this->assertNotSame(
            (int) $box->product_id,
            (int) $case->product_id,
            'One row is one unit in this layout, so these are two products. If that has changed, the page text has to change with it.'
        );
    }

    /**
     * Uploading a sample twice must not double the shelf or duplicate the goods. Staff learning the
     * page do exactly this.
     */
    public function test_loading_a_sample_twice_neither_duplicates_nor_doubles(): void
    {
        $this->attemptAuthenticate();

        $this->importFile( 'samples/sample-branch-export.csv' );
        $this->importFile( 'samples/sample-branch-export.csv' );

        $this->assertSame(
            1,
            ProductUnitQuantity::where( 'barcode', '8850111000017' )->count(),
            'The second upload made a second row for the same barcode.'
        );

        $this->assertSame(
            24.0,
            (float) $this->rowFor( '8850111000017' )->quantity,
            'Setting a figure twice must leave it at the figure, not at twice the figure.'
        );
    }

    /**
     * The blank templates carry no goods, so loading one has to change nothing at all rather than
     * report success over an empty file. Someone downloading a template and uploading it straight
     * back is the commonest first mistake on this page.
     */
    public function test_the_blank_templates_load_nothing(): void
    {
        $this->attemptAuthenticate();

        $before = Product::count();

        foreach ( [ 'samples/standard-product-template.csv', 'samples/divided-goods-template.csv' ] as $template ) {
            $this->importFile( $template );
        }

        $this->assertSame(
            $before,
            Product::count(),
            'An empty template created products out of its own guidance lines.'
        );
    }

    /**
     * The guidance inside each file quotes line numbers — "fill in from line 9", "do not touch the
     * header on line 8". Those numbers are typed into the text and nothing keeps them true when a line
     * is added or removed. A stale number sends a member of staff looking for a header one row from
     * where they were told it is, and the fault reads as the system refusing a correct file.
     *
     * Every number a file quotes has to be either its own header row or its own first data row.
     */
    public function test_the_line_numbers_written_inside_each_file_are_true_of_that_file(): void
    {
        $markers = [
            'samples/standard-product-template.csv' => 'บาร์โค้ดชิ้น',
            'samples/standard-product-example.csv' => 'บาร์โค้ดชิ้น',
            'samples/divided-goods-template.csv' => 'ขนาด1',
            'samples/divided-goods-example.csv' => 'ขนาด1',
            'samples/sample-branch-export.csv' => 'รหัสบาร์โค๊ดสินค้า',
        ];

        foreach ( $markers as $file => $headerMarker ) {
            $handle = fopen( public_path( $file ), 'r' );

            $this->assertNotFalse( $handle );

            $lines = [];

            while ( ( $line = fgetcsv( $handle ) ) !== false ) {
                $lines[] = $line;
            }

            fclose( $handle );

            if ( isset( $lines[ 0 ][ 0 ] ) ) {
                $lines[ 0 ][ 0 ] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $lines[ 0 ][ 0 ] );
            }

            $headerLine = null;
            $guidance = [];

            foreach ( $lines as $index => $line ) {
                $cells = array_map( 'trim', array_map( 'strval', $line ) );

                if ( in_array( $headerMarker, $cells, true ) ) {
                    $headerLine = $index + 1;

                    break;
                }

                $guidance[] = $cells[ 0 ] ?? '';
            }

            $this->assertNotNull( $headerLine, sprintf( '%s has no header row.', $file ) );

            $firstDataLine = $headerLine + 1;

            foreach ( $guidance as $offset => $text ) {
                if ( preg_match_all( '/บรรทัดที่\s*(\d+)/u', $text, $matches ) === 0 ) {
                    continue;
                }

                foreach ( $matches[ 1 ] as $claimed ) {
                    $this->assertContains(
                        (int) $claimed,
                        [ $headerLine, $firstDataLine ],
                        sprintf(
                            '%s line %d says "บรรทัดที่ %d", but its header is on line %d and its first row of data on line %d.',
                            $file,
                            $offset + 1,
                            (int) $claimed,
                            $headerLine,
                            $firstDataLine
                        )
                    );
                }
            }
        }
    }

    /**
     * Both worked examples carry invented products with invented barcodes, so both have to warn against
     * being uploaded. The standard one always did; the divided one did not until this was noticed.
     */
    public function test_both_worked_examples_warn_against_uploading_themselves(): void
    {
        foreach ( [ 'samples/standard-product-example.csv', 'samples/divided-goods-example.csv' ] as $file ) {
            $contents = (string) file_get_contents( public_path( $file ) );

            $this->assertStringContainsString(
                'ห้ามอัพไฟล์นี้เข้าระบบ',
                $contents,
                sprintf( '%s carries invented goods and has to say so.', $file )
            );
        }
    }

    /**
     * The legacy ladder sample is not offered on the page any more — standard-product-example.csv
     * replaced it and carries a column explaining each case. It is kept because it is the fixture
     * InventoryUploadLadderTest reads, and it still has to load, or that test would be pinning a
     * file that no longer works.
     */
    public function test_the_legacy_ladder_sample_still_loads_even_though_the_page_no_longer_offers_it(): void
    {
        $this->attemptAuthenticate();

        $path = 'samples/sample-unit-ladder.csv';

        $this->assertFileExists( public_path( $path ) );

        $this->importFile( $path );

        $this->assertNotNull(
            $this->rowFor( '8850001000011' ),
            'The legacy sample no longer loads. Either fix it or retire it together with the test that reads it.'
        );
    }
}
