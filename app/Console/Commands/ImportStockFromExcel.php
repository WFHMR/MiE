<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LocksInventoryImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportStockFromExcel extends Command
{
    use LocksInventoryImport;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stock:import {file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import stock from Excel file';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Increase execution time for large files
        set_time_limit( 0 );

        $filePath = $this->argument( 'file' );

        if ( ! file_exists( $filePath ) ) {
            $this->error( "File not found: {$filePath}" );

            return 1;
        }

        $this->info( "Reading Excel file: {$filePath}" );

        try {
            // Read Excel file
            $spreadsheet = IOFactory::load( $filePath );
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            $this->info( 'Total rows: ' . count( $rows ) );

            $successCount = 0;
            $errorCount = 0;
            $skippedCount = 0;

            // Progress bar
            $totalRows = count( $rows ) - 2; // Exclude header rows
            $bar = $this->output->createProgressBar( $totalRows );
            $bar->start();

            // Skip header rows
            foreach ( $rows as $index => $row ) {
                if ( $index < 2 ) {
                    continue; // Skip first 2 rows (headers)
                }

                // Extract data from Excel columns
                $sku = trim( $row[1] ?? '' ); // Column B (index 1)
                $barcode = trim( $row[2] ?? '' ); // Column C (index 2)
                $productName = trim( $row[3] ?? '' ); // Column D (index 3)
                $quantity = floatval( $row[5] ?? 0 ); // Column F (index 5) - quantity

                if ( empty( $sku ) && empty( $barcode ) ) {
                    $skippedCount++;
                    $bar->advance();

                    continue;
                }

                // Find product by SKU or barcode
                $product = DB::table( 'nexopos_products' )
                    ->where( 'sku', $sku )
                    ->orWhere( 'barcode', $barcode )
                    ->first();

                if ( ! $product ) {
                    $errorCount++;
                    $bar->advance();

                    continue;
                }

                // Get product unit quantities (without branch_id filter since records have NULL branch_id)
                $unitQuantity = DB::table( 'nexopos_products_unit_quantities' )
                    ->where( 'product_id', $product->id )
                    ->first();

                if ( ! $unitQuantity ) {
                    $errorCount++;
                    $bar->advance();

                    continue;
                }

                // Update stock quantity
                DB::table( 'nexopos_products_unit_quantities' )
                    ->where( 'id', $unitQuantity->id )
                    ->update( [
                        'quantity' => $quantity,
                        'updated_at' => now(),
                    ] );

                $successCount++;
                $bar->advance();
            }

            $bar->finish();
            $this->newLine( 2 );

            $this->info( '=== Import Summary ===' );
            $this->info( "✓ Success: {$successCount}" );
            $this->error( "✗ Errors: {$errorCount}" );
            $this->warn( "- Skipped: {$skippedCount}" );

            return 0;

        } catch ( \Exception $e ) {
            $this->error( 'Error reading Excel file: ' . $e->getMessage() );

            return 1;
        }
    }
}
