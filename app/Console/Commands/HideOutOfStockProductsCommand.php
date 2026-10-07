<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductUnitQuantity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The shop's export reports zero on hand for several hundred products. NexoPOS
 * lets a cashier add them to the cart and only refuses at payment, which wastes
 * the customer's time and leaves a half-built order on screen.
 *
 * Taking them off the till instead means they never reach the cart. Hiding rather
 * than deleting keeps historical orders resolving to a real product row, and the
 * products come back the moment a count arrives.
 *
 * Uses the same two fields as the importer's --hide-missing, which is the
 * convention in this codebase: status drives the POS grid and barcode lookup
 * through Product::onSale(), searchable keeps them out of product pickers.
 */
class HideOutOfStockProductsCommand extends Command
{
    protected $signature = 'stock:hide-out-of-stock
                            {--dry-run : Report what would change without writing}
                            {--restore : Put hidden products back on sale once they hold stock again}
                            {--samples=10 : How many affected products to show}';

    protected $description = 'Take products the export reports as out of stock off the till';

    public function handle(): int
    {
        return $this->option( 'restore' ) ? $this->restore() : $this->hide();
    }

    private function hide(): int
    {
        $ids = $this->emptyProductIds();

        $this->info( sprintf( 'Products at or below zero stock: %d', $ids->count() ) );

        $pending = Product::whereIn( 'id', $ids )
            ->where( 'status', Product::STATUS_AVAILABLE )
            ->count();

        $this->info( sprintf( 'Of those, still on sale: %d', $pending ) );
        $this->newLine();

        if ( $pending === 0 ) {
            $this->info( 'Nothing to change.' );

            return self::SUCCESS;
        }

        $this->showSamples( $ids );

        if ( $this->option( 'dry-run' ) ) {
            $this->warn( '[dry run] Nothing was written.' );

            return self::SUCCESS;
        }

        $updated = 0;

        foreach ( $ids->chunk( 500 ) as $chunk ) {
            DB::transaction( function () use ( $chunk, &$updated ) {
                $updated += Product::whereIn( 'id', $chunk )
                    ->where( 'status', Product::STATUS_AVAILABLE )
                    ->update( [
                        'status' => Product::STATUS_UNAVAILABLE,
                        'searchable' => false,
                    ] );
            } );
        }

        $this->info( sprintf( '%d product(s) taken off the till.', $updated ) );
        $this->newLine();
        $this->line( 'They stay in the catalogue and in past orders, they simply cannot be' );
        $this->line( 'added to a cart. Put them back with:' );
        $this->line( '  php artisan stock:hide-out-of-stock --restore' );

        return self::SUCCESS;
    }

    /**
     * Only restores products that actually hold stock now, so running this after a
     * partial delivery does not put empty products back on the till.
     */
    private function restore(): int
    {
        $withStock = ProductUnitQuantity::where( 'quantity', '>', 0 )
            ->distinct()
            ->pluck( 'product_id' );

        $pending = Product::whereIn( 'id', $withStock )
            ->where( 'status', Product::STATUS_UNAVAILABLE )
            ->count();

        $this->info( sprintf( 'Hidden products that now hold stock: %d', $pending ) );

        if ( $pending === 0 ) {
            $this->info( 'Nothing to restore.' );

            return self::SUCCESS;
        }

        if ( $this->option( 'dry-run' ) ) {
            $this->warn( '[dry run] Nothing was written.' );

            return self::SUCCESS;
        }

        $updated = 0;

        foreach ( $withStock->chunk( 500 ) as $chunk ) {
            DB::transaction( function () use ( $chunk, &$updated ) {
                $updated += Product::whereIn( 'id', $chunk )
                    ->where( 'status', Product::STATUS_UNAVAILABLE )
                    ->update( [
                        'status' => Product::STATUS_AVAILABLE,
                        'searchable' => true,
                    ] );
            } );
        }

        $this->info( sprintf( '%d product(s) back on sale.', $updated ) );

        return self::SUCCESS;
    }

    /**
     * A product counts as empty when none of its unit rows holds stock. Summing
     * would let a positive row mask a negative one.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function emptyProductIds(): \Illuminate\Support\Collection
    {
        $withStock = ProductUnitQuantity::where( 'quantity', '>', 0 )
            ->distinct()
            ->pluck( 'product_id' );

        return ProductUnitQuantity::whereNotIn( 'product_id', $withStock )
            ->distinct()
            ->pluck( 'product_id' );
    }

    /**
     * @param \Illuminate\Support\Collection<int, int> $ids
     */
    private function showSamples( \Illuminate\Support\Collection $ids ): void
    {
        $samples = max( 0, (int) $this->option( 'samples' ) );

        if ( $samples === 0 ) {
            return;
        }

        $this->line( 'Sample of products that would be hidden:' );

        $products = Product::whereIn( 'id', $ids )
            ->where( 'status', Product::STATUS_AVAILABLE )
            ->limit( $samples )
            ->get( [ 'id', 'sku' ] );

        foreach ( $products as $product ) {
            $this->line( sprintf( '  id=%d sku=%s', $product->id, $product->sku ) );
        }

        $this->newLine();
    }
}
