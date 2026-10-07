<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Provider;
use App\Services\ThaiCatalogLayout;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills in nexopos_products.provider_id from the shop workbook, and touches nothing else.
 *
 * The catalogue importer could do this — it reads the supplier column and now writes the link. But
 * re-running it against a trading shop rewrites names, categories, tax groups, statuses and prices for
 * every product in the file. Anything a member of staff corrected in the till since the last import
 * would be silently reverted to whatever the spreadsheet says, and a wrong price is money.
 *
 * So this exists instead. It matches on barcode, writes one column, and reports what it could not
 * match rather than inventing a supplier for it. It is safe to run on a live shop mid-trading, and
 * safe to run twice.
 */
class BackfillProductSuppliersCommand extends Command
{
    protected $signature = 'ns:backfill-product-suppliers
                            {file : the shop workbook, CSV}
                            {--dry-run : report what would change and write nothing}
                            {--overwrite : replace suppliers already recorded, instead of only filling blanks}';

    protected $description = 'Attaches the supplier recorded in the workbook to each product, writing only provider_id.';

    public function handle(): int
    {
        $path = (string) $this->argument( 'file' );

        if ( ! is_file( $path ) ) {
            $this->error( "File not found: {$path}" );

            return self::FAILURE;
        }

        $layout = $this->detectLayout( $path );

        if ( $layout === null ) {
            return self::FAILURE;
        }

        $pairs = $this->readBarcodeToSupplier( $path, $layout );

        if ( $pairs === [] ) {
            $this->error( 'No rows carried both a barcode and a supplier name.' );

            return self::FAILURE;
        }

        $this->info( sprintf( 'Rows with a barcode and a supplier: %d', count( $pairs ) ) );

        /**
         * Suppliers are matched to the ones already on file, never created.
         *
         * The catalogue import created all of them when it ran. If a name here matches nothing, that is
         * worth reporting rather than papering over: a new supplier appearing in a backfill means the
         * workbook and the database have diverged, and somebody should look at why.
         */
        $providers = Provider::all()->mapWithKeys( fn ( Provider $provider ) => [
            $this->normalise( trim( $provider->first_name . ' ' . ( $provider->last_name ?? '' ) ) ) => $provider->id,
        ] );

        $updated = 0;
        $alreadySet = 0;
        $productMissing = 0;
        $supplierMissing = [];

        $overwrite = (bool) $this->option( 'overwrite' );
        $dryRun = (bool) $this->option( 'dry-run' );

        $bar = $this->output->createProgressBar( count( $pairs ) );
        $bar->start();

        foreach ( array_chunk( $pairs, 500, true ) as $chunk ) {
            DB::transaction( function () use ( $chunk, $providers, $overwrite, $dryRun, &$updated, &$alreadySet, &$productMissing, &$supplierMissing ): void {
                foreach ( $chunk as $barcode => $supplierName ) {
                    $providerId = $providers[ $this->normalise( $supplierName ) ] ?? null;

                    if ( $providerId === null ) {
                        $supplierMissing[ $supplierName ] = ( $supplierMissing[ $supplierName ] ?? 0 ) + 1;

                        continue;
                    }

                    $product = Product::where( 'barcode', (string) $barcode )->first();

                    if ( ! $product instanceof Product ) {
                        $productMissing++;

                        continue;
                    }

                    if ( $product->provider_id !== null && ! $overwrite ) {
                        $alreadySet++;

                        continue;
                    }

                    if ( (int) $product->provider_id === (int) $providerId ) {
                        $alreadySet++;

                        continue;
                    }

                    if ( ! $dryRun ) {
                        /**
                         * One column, by primary key. Not $product->save(), which would rewrite every
                         * attribute the model is holding and fire the product events — and those cascade
                         * into stock and search reindexing on a shop that is currently selling.
                         */
                        DB::table( ( new Product )->getTable() )
                            ->where( 'id', $product->id )
                            ->update( [ 'provider_id' => $providerId ] );
                    }

                    $updated++;
                }
            } );

            $bar->advance( count( $chunk ) );
        }

        $bar->finish();
        $this->newLine( 2 );

        $this->table(
            [ 'Outcome', 'Products' ],
            [
                [ $dryRun ? 'Would be attached' : 'Supplier attached', $updated ],
                [ 'Already correct, left alone', $alreadySet ],
                [ 'Barcode not in the catalogue', $productMissing ],
                [ 'Supplier name not on file', array_sum( $supplierMissing ) ],
            ]
        );

        if ( $supplierMissing !== [] ) {
            $this->newLine();
            $this->warn( 'These supplier names in the workbook match no supplier on file:' );

            foreach ( $supplierMissing as $name => $count ) {
                $this->line( sprintf( '  %s (%d rows)', $name, $count ) );
            }
        }

        if ( $dryRun ) {
            $this->newLine();
            $this->warn( 'Dry run: nothing was written.' );
        }

        return self::SUCCESS;
    }

    /**
     * Reads the header instead of trusting a column number.
     *
     * This command shipped with the barcode pinned to column 2. The file the staff upload keeps it in
     * column 1 and puts the product name in 2, so every row was rejected for having a non-numeric
     * barcode and the command reported that the file contained nothing usable.
     */
    private function detectLayout( string $path ): ?ThaiCatalogLayout
    {
        $sample = [];
        $handle = fopen( $path, 'r' );

        if ( $handle === false ) {
            $this->error( 'The file could not be opened for reading.' );

            return null;
        }

        while ( count( $sample ) < 10 && ( $row = fgetcsv( $handle ) ) !== false ) {
            $sample[] = $row;
        }

        fclose( $handle );

        $layout = ThaiCatalogLayout::detect( $sample );
        $missing = $layout->missing( ThaiCatalogLayout::REQUIRED_FOR_SUPPLIERS );

        if ( $missing !== [] ) {
            $this->error( 'The header row could not be understood. Missing: ' . implode( ', ', $missing ) );
            $this->line( 'What was recognised: ' . ( $layout->describe() ?: 'nothing' ) );
            $this->line( 'Nothing was written. Check that the file still carries its header row.' );

            return null;
        }

        $this->info( sprintf(
            'Header found on line %d. Columns: %s',
            $layout->headerRowNumber(),
            $layout->describe()
        ) );

        return $layout;
    }

    /**
     * Barcode => supplier name, last row winning for a repeated barcode, which matches what the
     * catalogue import does when the workbook lists the same product twice.
     *
     * @return array<string, string>
     */
    private function readBarcodeToSupplier( string $path, ThaiCatalogLayout $layout ): array
    {
        $pairs = [];
        $handle = fopen( $path, 'r' );

        if ( $handle === false ) {
            return [];
        }

        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            $barcode = $layout->value( $row, ThaiCatalogLayout::BARCODE );
            $supplier = $layout->value( $row, ThaiCatalogLayout::SUPPLIER_NAME );
            $name = $layout->value( $row, ThaiCatalogLayout::NAME );

            /**
             * Header, section and total rows fall out here. A row with no product name is not a product,
             * and a barcode has to look like one.
             */
            if ( $barcode === '' || $supplier === '' || $name === '' ) {
                continue;
            }

            if ( ! ctype_digit( str_replace( '.', '', $barcode ) ) ) {
                continue;
            }

            $pairs[ $barcode ] = $supplier;
        }

        fclose( $handle );

        return $pairs;
    }

    /**
     * Names are compared with their inner spacing collapsed, because the workbook is inconsistent about
     * it and "บริษัท  ก  จำกัด" and "บริษัท ก จำกัด" are the same company.
     */
    private function normalise( string $name ): string
    {
        return (string) preg_replace( '/\s+/u', ' ', trim( $name ) );
    }
}
