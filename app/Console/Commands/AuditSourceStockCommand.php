<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductUnitQuantity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Reads the shop's export and compares every row against what the catalogue
 * actually holds, so a claim about stock can be settled with the data rather than
 * by reading one row by eye.
 *
 * Writes UTF-8 to a file because the Windows console code page turns Thai product
 * names into mojibake, and the names are what make the report readable.
 */
class AuditSourceStockCommand extends Command
{
    protected $signature = 'stock:audit-source
                            {file : Path to the .csv/.xls/.xlsx export}
                            {--find= : Report only rows whose name, stock code or barcode contains this}
                            {--mismatches=30 : How many quantity mismatches to list}
                            {--raw : With --find, dump every cell of the matched rows with its column index}
                            {--out= : Write the report to this UTF-8 file}';

    protected $description = 'Compare the stock column of the shop export against the catalogue';

    private const COL_STOCK_CODE = 1;

    private const COL_BARCODE = 2;

    private const COL_NAME = 3;

    private const COL_SALE_UNIT = 12;

    private const COL_STOCK_UNIT = 15;

    private const COL_QTY = 16;

    /**
     * @var array<int, string>
     */
    private array $lines = [];

    public function handle(): int
    {
        $path = $this->argument( 'file' );

        if ( ! is_file( $path ) ) {
            $this->error( 'File not found: ' . $path );

            return self::FAILURE;
        }

        $this->info( 'Reading ' . $path . ' ...' );

        $rows = $this->readRows( $path );

        $this->write( sprintf( 'Rows with data: %d', count( $rows ) ) );
        $this->write( '' );

        $find = $this->option( 'find' );

        if ( ! empty( $find ) ) {
            $this->reportMatchingRows( $rows, $find );
        } else {
            $this->reportSummary( $rows );
        }

        $out = $this->option( 'out' );

        if ( ! empty( $out ) ) {
            File::put( $out, implode( PHP_EOL, $this->lines ) . PHP_EOL );
            $this->info( 'Report written to ' . $out );
        }

        return self::SUCCESS;
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     */
    private function reportSummary( array $rows ): void
    {
        $zero = 0;
        $positive = 0;
        $blank = 0;
        $sum = 0.0;
        $mismatches = [];
        $missingProduct = 0;

        foreach ( $rows as $row ) {
            $name = $this->text( $row[ self::COL_NAME ] ?? null );

            if ( $name === '' ) {
                continue;
            }

            $raw = $this->text( $row[ self::COL_QTY ] ?? null );

            if ( $raw === '' ) {
                $blank++;
            }

            $quantity = $this->number( $raw );

            if ( $quantity > 0 ) {
                $positive++;
                $sum += $quantity;
            } else {
                $zero++;
            }

            $identity = $this->identity( $row );
            $product = Product::where( 'barcode', $identity )->first();

            if ( ! $product instanceof Product ) {
                $missingProduct++;

                continue;
            }

            $saleUnit = $this->text( $row[ self::COL_SALE_UNIT ] ?? null );
            $stored = ProductUnitQuantity::where( 'product_id', $product->id )->sum( 'quantity' );
            $rowsForProduct = ProductUnitQuantity::where( 'product_id', $product->id )->count();

            if ( abs( ( (float) $stored ) - $quantity ) > 0.00001 && count( $mismatches ) < (int) $this->option( 'mismatches' ) ) {
                $mismatches[] = sprintf(
                    '  %s | barcode=%s | source=%s | stored_total=%s across %d unit row(s) | sale_unit=%s',
                    $name,
                    $identity,
                    $quantity,
                    $stored,
                    $rowsForProduct,
                    $saleUnit
                );
            }
        }

        $this->write( 'Source stock column (คงเหลือ):' );
        $this->write( sprintf( '  rows with quantity > 0 : %d', $positive ) );
        $this->write( sprintf( '  rows with quantity <= 0: %d', $zero ) );
        $this->write( sprintf( '  rows with blank cell   : %d', $blank ) );
        $this->write( sprintf( '  total units on hand    : %s', number_format( $sum, 2 ) ) );
        $this->write( '' );
        $this->write( sprintf( 'Rows whose barcode has no product: %d', $missingProduct ) );
        $this->write( '' );

        if ( $mismatches === [] ) {
            $this->write( 'Every matched product holds exactly the source quantity.' );

            return;
        }

        $this->write( 'Quantity mismatches (source vs catalogue):' );

        foreach ( $mismatches as $line ) {
            $this->write( $line );
        }
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     */
    private function reportMatchingRows( array $rows, string $find ): void
    {
        $found = 0;

        foreach ( $rows as $index => $row ) {
            $name = $this->text( $row[ self::COL_NAME ] ?? null );
            $stockCode = $this->text( $row[ self::COL_STOCK_CODE ] ?? null );
            $barcode = $this->text( $row[ self::COL_BARCODE ] ?? null );

            $haystack = $name . ' ' . $stockCode . ' ' . $barcode;

            if ( mb_stripos( $haystack, $find ) === false ) {
                continue;
            }

            $found++;

            $this->write( sprintf( 'Source row %d: %s', $index + 4, $name ) );

            /**
             * Column mapping is the one assumption behind every number in this
             * report, so it has to be possible to see the sheet exactly as the
             * reader returned it rather than take the mapping on trust.
             */
            if ( $this->option( 'raw' ) ) {
                $headers = [
                    0 => 'อันดับ', 1 => 'รหัสสต็อก', 2 => 'บาร์โค๊ต', 3 => 'ชื่อสินค้า',
                    4 => '(ว่าง)', 5 => 'บรรจุ', 6 => 'หลัก', 7 => 'ย่อย', 8 => 'ย่อย',
                    9 => 'ผู้จำหน่าย', 10 => 'ชื่อบริษัท', 11 => 'วันที่สร้าง',
                    12 => 'หน่วยขาย', 13 => 'ราคาทุน', 14 => 'ขาย (VAT)',
                    15 => 'หน่วยสต็อก', 16 => 'คงเหลือ',
                ];

                foreach ( $row as $column => $cell ) {
                    $this->write( sprintf(
                        '    [%2d] %-12s = "%s"',
                        $column,
                        $headers[ $column ] ?? '?',
                        $this->text( $cell )
                    ) );
                }
            }
            $this->write( sprintf(
                '  stock_code=%s barcode=%s sale_unit=%s stock_unit=%s qty_raw="%s" qty=%s',
                $stockCode,
                $barcode,
                $this->text( $row[ self::COL_SALE_UNIT ] ?? null ),
                $this->text( $row[ self::COL_STOCK_UNIT ] ?? null ),
                $this->text( $row[ self::COL_QTY ] ?? null ),
                $this->number( $this->text( $row[ self::COL_QTY ] ?? null ) )
            ) );

            $identity = $this->identity( $row );
            $product = Product::where( 'barcode', $identity )->first();

            if ( ! $product instanceof Product ) {
                $this->write( '  no product in the catalogue with barcode ' . $identity );
                $this->write( '' );

                continue;
            }

            $this->write( sprintf( '  catalogue product id=%d sku=%s barcode=%s', $product->id, $product->sku, $product->barcode ) );

            $unitQuantities = ProductUnitQuantity::with( 'unit:id,name' )
                ->where( 'product_id', $product->id )
                ->get();

            foreach ( $unitQuantities as $unitQuantity ) {
                $this->write( sprintf(
                    '    unit=%s quantity=%s sale_price=%s',
                    $unitQuantity->unit->name ?? 'unknown',
                    $unitQuantity->quantity,
                    $unitQuantity->sale_price
                ) );
            }

            $this->write( '' );
        }

        $this->write( sprintf( 'Matched %d source row(s) for "%s"', $found, $find ) );
    }

    /**
     * Mirrors the identity the catalogue importer assigns, so a row is compared to
     * the product it actually created.
     *
     * @param array<int, mixed> $row
     */
    private function identity( array $row ): string
    {
        $barcode = $this->text( $row[ self::COL_BARCODE ] ?? null );
        $stockCode = $this->text( $row[ self::COL_STOCK_CODE ] ?? null );

        return $barcode !== '' ? $barcode : $stockCode;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function readRows( string $path ): array
    {
        $reader = IOFactory::createReaderForFile( $path );
        $reader->setReadDataOnly( true );

        $sheet = $reader->load( $path )->getSheet( 0 );

        return $sheet->rangeToArray( 'A4:Q' . $sheet->getHighestDataRow(), null, true, false );
    }

    private function text( mixed $value ): string
    {
        return trim( (string) ( $value ?? '' ) );
    }

    private function number( string $value ): float
    {
        $clean = str_replace( [ ',', ' ' ], '', $value );

        return is_numeric( $clean ) ? (float) $clean : 0.0;
    }

    private function write( string $line ): void
    {
        $this->lines[] = $line;
        $this->line( $line );
    }
}
