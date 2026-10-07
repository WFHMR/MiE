<?php

namespace Tests\Feature;

use App\Services\ThaiCatalogLayout;
use Tests\TestCase;

/**
 * Two different workbook layouts exist, and both commands that read them had the columns hard coded for
 * the one nobody uploads.
 *
 * The consequence was not a crash. The importer read the product name where it expected a barcode, and
 * read past the end of the row where it expected the cost and the selling price — so it would have
 * written empty prices over a live price list and said nothing. The empty description on all 2,644
 * products is the visible trace of the same shift.
 *
 * So the fix is not a corrected number. A corrected number is right until the next export changes, and
 * the failure mode is silent. The header is read, and a file that cannot be understood is refused.
 */
class ThaiCatalogLayoutTest extends TestCase
{
    /**
     * The layout the staff actually upload: 13 columns, barcode second, and one heading with its tone
     * mark typed twice.
     *
     * @return array<int, array<int, string>>
     */
    private function uploadedLayout(): array
    {
        return [
            [ 'รายงานแสดงทะเบียนรายการสินค้าที่ขายทั้งหมด', '', '', '', '', '', '', '', '', '', 'พิมพ์วันที่ :', '10/09/2026', '' ],
            [ 'รหัสสต็อก', 'รหัสบาร์โค๊ดสินค้า', 'ชื่อ/รายละเอียดสินค้า', 'ปริมาณ/ขนาด', 'หน่วย', 'บรรจุ', 'หน่วยขาย', 'กลุ่มหลัก', 'ย่่อย', 'ผู้จำหน่าย', 'ชื่อ/บริษัท', 'ราคาทุน', 'ขาย (VAT)' ],
            [ '11.55', '11.55', 'ขนมปังรวมมิตร ปี๊บเล็ก 1.5 กก.', '', '', '1', 'ปี๊บ', '1', '1', 'SP580043', 'ตลาดไท สุชาติ วงษ์เพ็ง', '0.00', '150.00' ],
        ];
    }

    public function test_it_finds_the_columns_in_the_file_the_staff_upload(): void
    {
        $layout = ThaiCatalogLayout::detect( $this->uploadedLayout() );

        $this->assertSame( 2, $layout->headerRowNumber(), 'The header is on the second line, after the title.' );

        $this->assertSame( 1, $layout->indexOf( ThaiCatalogLayout::BARCODE ), 'Barcode is column 1 in this file, not 2.' );
        $this->assertSame( 2, $layout->indexOf( ThaiCatalogLayout::NAME ) );
        $this->assertSame( 6, $layout->indexOf( ThaiCatalogLayout::SALE_UNIT ) );
        $this->assertSame( 7, $layout->indexOf( ThaiCatalogLayout::CATEGORY_MAIN ) );
        $this->assertSame( 9, $layout->indexOf( ThaiCatalogLayout::SUPPLIER_CODE ) );
        $this->assertSame( 10, $layout->indexOf( ThaiCatalogLayout::SUPPLIER_NAME ) );
        $this->assertSame( 11, $layout->indexOf( ThaiCatalogLayout::COST ) );
        $this->assertSame( 12, $layout->indexOf( ThaiCatalogLayout::PRICE ) );

        $this->assertSame( [], $layout->missing( ThaiCatalogLayout::REQUIRED_FOR_SUPPLIERS ) );
        $this->assertSame( [], $layout->missing( ThaiCatalogLayout::REQUIRED_FOR_IMPORT ) );
    }

    /**
     * `หน่วยขาย` contains `หน่วย`, and `ขาย (VAT)` contains `ขาย`. A looser match would hand the selling
     * price column to the unit, and the till would price everything at zero.
     */
    public function test_it_does_not_confuse_the_unit_column_with_the_price_column(): void
    {
        $layout = ThaiCatalogLayout::detect( $this->uploadedLayout() );

        $this->assertNotSame(
            $layout->indexOf( ThaiCatalogLayout::SALE_UNIT ),
            $layout->indexOf( ThaiCatalogLayout::PRICE ),
            'The unit and the price were matched to the same column.'
        );

        $this->assertSame( 12, $layout->indexOf( ThaiCatalogLayout::PRICE ) );
    }

    /**
     * The heading in the real file is `ย่่อย` — the tone mark is typed twice. Comparing raw text misses
     * it, and a missed sub category is not noticed until the data is already wrong.
     */
    public function test_it_reads_a_heading_whose_tone_mark_was_typed_twice(): void
    {
        $layout = ThaiCatalogLayout::detect( $this->uploadedLayout() );

        $this->assertSame( 8, $layout->indexOf( ThaiCatalogLayout::CATEGORY_SUB ) );
    }

    /**
     * Shifting every column by one, as a wider export would, must be detected rather than absorbed.
     */
    public function test_it_reports_a_shifted_layout_rather_than_reading_the_wrong_columns(): void
    {
        $rows = $this->uploadedLayout();

        foreach ( $rows as $index => $row ) {
            array_unshift( $row, 'ลำดับ' );
            $rows[ $index ] = $row;
        }

        $layout = ThaiCatalogLayout::detect( $rows );

        $this->assertSame(
            2,
            $layout->indexOf( ThaiCatalogLayout::BARCODE ),
            'A file with an extra leading column must report the barcode one place further along.'
        );
    }

    public function test_a_file_with_no_header_is_refused_rather_than_guessed_at(): void
    {
        $layout = ThaiCatalogLayout::detect( [
            [ '11.55', '11.55', 'ขนมปังรวมมิตร', '', '', '1', 'ปี๊บ' ],
            [ '11.56', '11.56', 'ขนมปังไส้สังขยา', '', '', '1', 'ปี๊บ' ],
        ] );

        $this->assertNotSame(
            [],
            $layout->missing( ThaiCatalogLayout::REQUIRED_FOR_SUPPLIERS ),
            'A file with no header was accepted, which means columns were assumed.'
        );
    }

    /**
     * The disagreement the importer's guard is there to catch, stated as data.
     *
     * The importer only accepts .xls/.xlsx, so driving it end to end from a CSV fixture never reaches
     * the guard — it stops on the file type first. Rather than assert that it failed, which would pass
     * even with the guard deleted, this checks the thing the guard compares: the positions written into
     * the command against the positions the real header reports.
     */
    public function test_the_real_file_disagrees_with_the_positions_the_importer_reads(): void
    {
        $layout = ThaiCatalogLayout::detect( $this->uploadedLayout() );

        /**
         * The constants inside ImportThaiProductCatalogCommand, which describe a wider export.
         */
        $assumed = [
            ThaiCatalogLayout::BARCODE => 2,
            ThaiCatalogLayout::NAME => 3,
            ThaiCatalogLayout::SALE_UNIT => 12,
            ThaiCatalogLayout::COST => 13,
            ThaiCatalogLayout::PRICE => 14,
        ];

        $disagreements = [];

        foreach ( $assumed as $field => $position ) {
            if ( $layout->indexOf( $field ) !== $position ) {
                $disagreements[] = $field;
            }
        }

        $this->assertSame(
            [ ThaiCatalogLayout::BARCODE, ThaiCatalogLayout::NAME, ThaiCatalogLayout::SALE_UNIT, ThaiCatalogLayout::COST, ThaiCatalogLayout::PRICE ],
            $disagreements,
            'Every one of these columns sits somewhere other than where the importer reads it. Cost and'
                . ' price are read past the end of a 13 column row, which is how empty prices would have'
                . ' been written over a live price list.'
        );
    }

    /**
     * And the guard has to be in the importer, refusing, rather than merely noted somewhere.
     */
    public function test_the_importer_carries_the_guard_and_names_the_safe_alternative(): void
    {
        $source = (string) file_get_contents(
            base_path( 'app/Console/Commands/ImportThaiProductCatalogCommand.php' )
        );

        $this->assertStringContainsString(
            'ThaiCatalogLayout::detect',
            $source,
            'The importer no longer checks the header, so it will happily read the wrong columns again.'
        );

        $this->assertStringContainsString(
            'does not have the layout this command was written for',
            $source,
            'The importer does not warn about a mismatched file.'
        );

        $this->assertStringContainsString(
            'On a shop that is trading, that is money',
            $source,
            'The warning does not say what the consequence is, so it reads as noise and gets ignored.'
        );

        $this->assertStringContainsString(
            'ns:backfill-product-suppliers',
            $source,
            'The refusal does not point at the command that is safe to run on a trading shop.'
        );
    }
}
