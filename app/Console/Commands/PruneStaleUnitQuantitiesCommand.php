<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Unit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * An earlier catalogue import parsed the stock column without stripping thousands
 * separators, so "47,675" became 47, and it resolved the selling unit to the
 * fallback instead of the one on the row. Both mistakes were written as an extra
 * unit quantity row per product, which the current import cannot overwrite because
 * it only touches the row matching the real selling unit.
 *
 * Those leftover rows carry a wrong price and phantom stock, and the till can pick
 * either of them, so they have to go. A product's unit_group is set from its
 * selling unit on every import, which makes it the authority on which unit rows
 * are legitimate: any row whose unit belongs to a different group is leftover.
 */
class PruneStaleUnitQuantitiesCommand extends Command
{
    protected $signature = 'stock:prune-stale-units
                            {--dry-run : Report what would be deleted without writing}
                            {--samples=15 : How many affected products to show}';

    protected $description = 'Delete unit quantity rows whose unit does not belong to the product unit group';

    public function handle(): int
    {
        $dryRun = (bool) $this->option( 'dry-run' );
        $samples = max( 0, (int) $this->option( 'samples' ) );

        $unitGroups = Unit::pluck( 'group_id', 'id' );

        if ( $unitGroups->isEmpty() ) {
            $this->error( 'No units found, refusing to touch anything.' );

            return self::FAILURE;
        }

        $this->info( 'Scanning unit quantity rows...' );

        $stale = [];
        $productsWithoutValidRow = [];

        Product::select( [ 'id', 'name', 'unit_group' ] )
            ->chunkById( 500, function ( $chunk ) use ( $unitGroups, &$stale, &$productsWithoutValidRow ) {
                $rows = ProductUnitQuantity::whereIn( 'product_id', $chunk->pluck( 'id' ) )
                    ->get( [ 'id', 'product_id', 'unit_id', 'quantity', 'sale_price' ] )
                    ->groupBy( 'product_id' );

                foreach ( $chunk as $product ) {
                    $productRows = $rows->get( $product->id );

                    if ( $productRows === null ) {
                        continue;
                    }

                    $keep = $productRows->filter(
                        fn ( $row ) => (int) ( $unitGroups[ $row->unit_id ] ?? 0 ) === (int) $product->unit_group
                    );

                    /**
                     * Deleting every row would take the product off the till entirely,
                     * which is worse than leaving a wrong price in place. Skip these
                     * and report them so they can be looked at.
                     */
                    if ( $keep->isEmpty() ) {
                        $productsWithoutValidRow[] = $product;

                        continue;
                    }

                    foreach ( $productRows as $row ) {
                        if ( (int) ( $unitGroups[ $row->unit_id ] ?? 0 ) !== (int) $product->unit_group ) {
                            $stale[] = [
                                'id' => $row->id,
                                'product_id' => $product->id,
                                'product_name' => $product->name,
                                'quantity' => $row->quantity,
                                'sale_price' => $row->sale_price,
                            ];
                        }
                    }
                }
            } );

        $this->newLine();
        $this->info( sprintf( 'Stale unit quantity rows: %d', count( $stale ) ) );
        $this->info( sprintf( 'Products that would be left with no unit row (skipped): %d', count( $productsWithoutValidRow ) ) );
        $this->newLine();

        if ( $samples > 0 && $stale !== [] ) {
            $this->line( 'Sample of what would be removed:' );

            foreach ( array_slice( $stale, 0, $samples ) as $row ) {
                $this->line( sprintf(
                    '  product_id=%d quantity=%s sale_price=%s',
                    $row[ 'product_id' ],
                    $row[ 'quantity' ],
                    $row[ 'sale_price' ]
                ) );
            }

            $this->newLine();
        }

        if ( $stale === [] ) {
            $this->info( 'Nothing to prune.' );

            return self::SUCCESS;
        }

        if ( $dryRun ) {
            $this->warn( '[dry run] Nothing was written.' );

            return self::SUCCESS;
        }

        $deleted = 0;

        foreach ( array_chunk( array_column( $stale, 'id' ), 500 ) as $ids ) {
            DB::transaction( function () use ( $ids, &$deleted ) {
                $deleted += ProductUnitQuantity::whereIn( 'id', $ids )->delete();
            } );
        }

        $this->info( sprintf( 'Deleted %d stale unit quantity row(s).', $deleted ) );

        return self::SUCCESS;
    }
}
