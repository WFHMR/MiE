<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Services\BarcodeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Draws the barcode images the label page prints.
 *
 * The images are only written when a product is saved through the product form or received through a
 * procurement. A catalogue loaded by CSV import has none — on this shop 2,922 of 2,928 barcodes had no
 * image and every label printed a broken picture.
 *
 * The label page draws each image the first time it is asked for, so this command is not required for
 * labels to work. It exists so the first print of the day is not the one waiting for thousands of
 * images to be drawn one request at a time, and so the ones that cannot be drawn at all are known
 * before a member of staff meets them at the printer.
 */
class GenerateBarcodeImagesCommand extends Command
{
    protected $signature = 'rgbf:barcodes-generate
        {--branch= : Only barcodes stocked by this branch}
        {--force : Redraw images that already exist}
        {--dry-run : Report what would be drawn without writing anything}
        {--limit=0 : Stop after this many barcodes, 0 for all}';

    protected $description = 'สร้างรูปบาร์โค้ดสำหรับหน้าพิมพ์ฉลาก ข้ามรูปที่มีอยู่แล้ว';

    public function handle( BarcodeService $barcodes ): int
    {
        $branchId = $this->option( 'branch' ) !== null ? (int) $this->option( 'branch' ) : null;
        $force = (bool) $this->option( 'force' );
        $dryRun = (bool) $this->option( 'dry-run' );
        $limit = max( 0, (int) $this->option( 'limit' ) );

        $barcodeTypes = $this->collectBarcodes( $branchId, $limit );

        if ( $barcodeTypes === [] ) {
            $this->info( 'ไม่มีบาร์โค้ดที่ต้องสร้าง' );

            return self::SUCCESS;
        }

        $this->line( sprintf( 'บาร์โค้ดที่จะตรวจ : %d รายการ', count( $barcodeTypes ) ) );

        if ( $branchId !== null ) {
            $this->line( sprintf( 'เฉพาะสาขา : %d', $branchId ) );
        }

        $disk = Storage::disk( 'public' );
        $created = 0;
        $skipped = 0;
        $failed = [];

        $bar = $this->output->createProgressBar( count( $barcodeTypes ) );
        $bar->start();

        foreach ( $barcodeTypes as $barcode => $type ) {
            $barcode = (string) $barcode;
            $path = $barcodes->imagePathFor( $barcode );
            $exists = $disk->exists( $path );

            if ( $exists && ! $force ) {
                $skipped++;
                $bar->advance();

                continue;
            }

            if ( $dryRun ) {
                $created++;
                $bar->advance();

                continue;
            }

            /**
             * --force redraws, so the existing file is removed first: ensureImage() keeps whatever is
             * already there, which is the behaviour every other caller wants.
             */
            if ( $exists && $force ) {
                $disk->delete( $path );
            }

            if ( $barcodes->ensureImage( $barcode, $type ) ) {
                $created++;
            } else {
                $failed[] = $barcode;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine( 2 );

        $this->table(
            [ 'รายการ', 'จำนวน' ],
            [
                [ $dryRun ? 'จะสร้าง' : 'สร้างแล้ว', $created ],
                [ 'มีอยู่แล้ว ข้ามไป', $skipped ],
                [ 'สร้างไม่ได้', count( $failed ) ],
            ]
        );

        if ( $failed !== [] ) {
            /**
             * Reported rather than counted only. A barcode that cannot be drawn even as CODE-128 holds
             * a character no symbology can carry, and it is a data problem someone has to look at —
             * the product will otherwise reach the label printer with nothing to scan.
             */
            $this->warn( 'บาร์โค้ดที่สร้างรูปไม่ได้ ต้องแก้ค่าในทะเบียนสินค้า :' );

            foreach ( array_slice( $failed, 0, 20 ) as $barcode ) {
                $this->line( '  ' . $barcode );
            }

            if ( count( $failed ) > 20 ) {
                $this->line( sprintf( '  ... และอีก %d รายการ', count( $failed ) - 20 ) );
            }
        }

        if ( $dryRun ) {
            $this->info( 'โหมดทดลอง ไม่ได้เขียนไฟล์อะไรเลย' );
        }

        return self::SUCCESS;
    }

    /**
     * Every barcode the catalogue uses, mapped to the type its product declares.
     *
     * Unit rows come first because that is what a label prints, then the product's own barcode, which
     * the till also scans. Keyed by barcode so the same code shared by several rows is drawn once.
     *
     * @return array<string, string|null>
     */
    private function collectBarcodes( ?int $branchId, int $limit ): array
    {
        $collected = [];

        ProductUnitQuantity::query()
            ->select( 'nexopos_products_unit_quantities.barcode', 'products.barcode_type' )
            ->join( 'nexopos_products as products', 'products.id', '=', 'nexopos_products_unit_quantities.product_id' )
            ->whereNotNull( 'nexopos_products_unit_quantities.barcode' )
            ->where( 'nexopos_products_unit_quantities.barcode', '<>', '' )
            ->when(
                $branchId !== null,
                fn ( $query ) => $query->where( 'nexopos_products_unit_quantities.branch_id', $branchId )
            )
            ->orderBy( 'nexopos_products_unit_quantities.id' )
            ->chunk( 500, function ( $rows ) use ( &$collected, $limit ): bool {
                foreach ( $rows as $row ) {
                    $collected[ (string) $row->barcode ] ??= $row->barcode_type;

                    if ( $limit > 0 && count( $collected ) >= $limit ) {
                        return false;
                    }
                }

                return true;
            } );

        /**
         * A branch filter is a filter on stock rows; the product's own barcode belongs to no branch,
         * so it is only collected when the whole catalogue is being drawn.
         */
        if ( $branchId === null ) {
            Product::query()
                ->select( 'barcode', 'barcode_type' )
                ->whereNotNull( 'barcode' )
                ->where( 'barcode', '<>', '' )
                ->orderBy( 'id' )
                ->chunk( 500, function ( $rows ) use ( &$collected, $limit ): bool {
                    foreach ( $rows as $row ) {
                        if ( $limit > 0 && count( $collected ) >= $limit && ! isset( $collected[ (string) $row->barcode ] ) ) {
                            return false;
                        }

                        $collected[ (string) $row->barcode ] ??= $row->barcode_type;
                    }

                    return true;
                } );
        }

        return $collected;
    }
}
