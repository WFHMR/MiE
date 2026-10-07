<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductUnitQuantity;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The shop exports the same report as .xls and as .csv. PhpSpreadsheet hands back
 * real numbers from the former and formatted text from the latter, so the importer
 * saw "47,675" where it used to see 47675.
 *
 * A (float) cast stops at the thousands separator, which turned 47,675 units into
 * 47 and a price of 1,700.00 into 1. Nothing failed and nothing was logged: the
 * catalogue simply came out with quantities and prices a thousandth of their real
 * value, which reads as plausible until the till refuses a sale.
 */
class ImportCatalogFromCsvTest extends TestCase
{
    use DatabaseTransactions;

    private string $csv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->csv = storage_path( 'framework/testing/catalog-' . uniqid() . '.csv' );

        if ( ! is_dir( dirname( $this->csv ) ) ) {
            mkdir( dirname( $this->csv ), 0777, true );
        }

        $this->writeCsv();
    }

    protected function tearDown(): void
    {
        if ( is_file( $this->csv ) ) {
            unlink( $this->csv );
        }

        parent::tearDown();
    }

    public function test_it_keeps_quantities_that_carry_thousands_separators(): void
    {
        $this->assertSame( 0, $this->import() );

        $product = Product::where( 'barcode', '8850002000011' )->firstOrFail();
        $quantity = ProductUnitQuantity::where( 'product_id', $product->id )->sum( 'quantity' );

        $this->assertEquals(
            47675,
            $quantity,
            'A quantity written as "47,675" must import as 47675, not 47.'
        );
    }

    public function test_it_keeps_prices_and_costs_that_carry_thousands_separators(): void
    {
        $this->assertSame( 0, $this->import() );

        $product = Product::where( 'barcode', '8850002000011' )->firstOrFail();
        $unitQuantity = ProductUnitQuantity::where( 'product_id', $product->id )->firstOrFail();

        $this->assertEquals(
            1700.0,
            $unitQuantity->sale_price,
            'A price written as "1,700.00 " must import as 1700, not 1.'
        );

        $this->assertEquals( 1616.67, $unitQuantity->cogs );
    }

    public function test_it_still_reads_plain_numbers_and_genuine_zeroes(): void
    {
        $this->assertSame( 0, $this->import() );

        $plain = Product::where( 'barcode', '8850002000028' )->firstOrFail();
        $plainQuantity = ProductUnitQuantity::where( 'product_id', $plain->id )->firstOrFail();

        $this->assertEquals( 500, $plainQuantity->quantity );
        $this->assertEquals( 50, $plainQuantity->sale_price );

        /**
         * A genuine zero has to stay zero. Reading it as anything else would put
         * phantom stock on the till for products the shop does not have.
         */
        $outOfStock = Product::where( 'barcode', '8850002000035' )->firstOrFail();
        $outOfStockQuantity = ProductUnitQuantity::where( 'product_id', $outOfStock->id )->firstOrFail();

        $this->assertEquals( 0, $outOfStockQuantity->quantity );
    }

    private function import(): int
    {
        return Artisan::call( 'rgbf:import-catalog', [ 'file' => $this->csv ] );
    }

    /**
     * Mirrors the real export: two title rows, header on row 3, data from row 4,
     * and numbers rendered the way the shop's reporting tool renders them, with
     * separators and trailing spaces.
     */
    private function writeCsv(): void
    {
        $lines = [
            'รายงานรายการสินค้าที่ขายได้,,,,,,,,,,,,พิมพ์วันที่ :,,,,',
            'ระหว่างวันที่ 30/12/2568  ถึงวันที่ 30/08/2569,,,,,,,,,,,,,,,,',
            'อันดับ,รหัสสต็อก,รหัสบาร์โค๊ตสินค้า,ชื่อ/รายละเอียดสินค้า,,บรรจุ,หลัก,ย่อย ,ย่อย ,ผู้จำหน่าย,ชื่อ/บริษัท,วันที่สร้าง,หน่วยขาย,ราคาทุน,ขาย (VAT),หน่วยสต็อก,คงเหลือ',
            '1,C001,8850002000011,ผงวุ้นทดสอบหลักพัน,,50 กรัม*25,01,05,,SP001,บจก. ทดสอบ,15/09/49,กระปุก,"1,616.67 ","1,700.00 ",ซอง,"47,675"',
            '2,C002,8850002000028,สินค้าเลขธรรมดา,,500 กรัม,04,03,,,,15/09/49,ถุง,3.50 ,50.00 ,ถุง,500',
            '3,C003,8850002000035,สินค้าหมดสต็อกจริง,,500 กรัม,04,03,,,,15/09/49,ถุง,3.50 ,50.00 ,ถุง,0',
        ];

        file_put_contents( $this->csv, implode( "\n", $lines ) . "\n" );
    }
}
