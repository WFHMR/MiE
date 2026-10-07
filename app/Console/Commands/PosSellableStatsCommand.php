<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductUnitQuantity;
use Illuminate\Console\Command;

/**
 * Reports what the till can actually sell, using the same scope the POS grid and
 * the barcode lookup use, so the number matches what a cashier sees rather than a
 * count of catalogue rows.
 */
class PosSellableStatsCommand extends Command
{
    protected $signature = 'pos:sellable-stats';

    protected $description = 'Report how many products the POS can sell';

    public function handle(): int
    {
        $total = Product::count();
        $onSale = Product::onSale()->count();
        $hidden = Product::hidden()->count();

        $withStock = ProductUnitQuantity::where( 'quantity', '>', 0 )
            ->distinct()
            ->pluck( 'product_id' );

        $onSaleWithStock = Product::onSale()->whereIn( 'id', $withStock )->count();
        $onSaleWithoutStock = Product::onSale()->whereNotIn( 'id', $withStock )->count();

        $this->info( 'Catalogue' );
        $this->line( sprintf( '  products total        : %d', $total ) );
        $this->line( sprintf( '  on sale               : %d', $onSale ) );
        $this->line( sprintf( '  hidden                : %d', $hidden ) );
        $this->newLine();

        $this->info( 'On sale, broken down by stock' );
        $this->line( sprintf( '  holding stock         : %d', $onSaleWithStock ) );
        $this->line( sprintf( '  empty (would refuse)  : %d', $onSaleWithoutStock ) );
        $this->newLine();

        if ( $onSaleWithoutStock > 0 ) {
            $this->warn( sprintf(
                '%d product(s) are on sale with no stock, so the till will refuse them at payment.',
                $onSaleWithoutStock
            ) );
            $this->warn( 'Take them off with: php artisan stock:hide-out-of-stock' );

            return self::SUCCESS;
        }

        $this->info( 'Every product on sale holds stock, so no sale can be refused for stock.' );

        return self::SUCCESS;
    }
}
