<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductUnitQuantity;
use Illuminate\Console\Command;

class StockStatsCommand extends Command
{
    protected $signature = 'stock:stats {--list-empty=0 : List up to N products still sitting at zero stock}';

    protected $description = 'Summarise stock coverage across products';

    public function handle(): int
    {
        $products = Product::count();
        $unitQuantities = ProductUnitQuantity::count();
        $withStock = ProductUnitQuantity::where( 'quantity', '>', 0 )->count();
        $zeroStock = ProductUnitQuantity::where( 'quantity', '<=', 0 )->count();

        $this->info( 'Products: ' . $products );
        $this->info( 'Unit quantity rows: ' . $unitQuantities );
        $this->info( 'Rows with stock > 0: ' . $withStock );
        $this->info( 'Rows with stock <= 0: ' . $zeroStock );
        $this->newLine();

        $this->info( 'Stock management distribution:' );

        $distribution = Product::selectRaw( 'stock_management, count(*) as aggregate' )
            ->groupBy( 'stock_management' )
            ->pluck( 'aggregate', 'stock_management' );

        foreach ( $distribution as $value => $count ) {
            $this->line( sprintf( '  %s => %d', $value === '' || $value === null ? '(empty)' : $value, $count ) );
        }

        $limit = (int) $this->option( 'list-empty' );

        if ( $limit > 0 ) {
            $this->newLine();
            $this->info( sprintf( 'Products still at zero stock (first %d):', $limit ) );

            $empty = ProductUnitQuantity::with( 'product:id,name,sku' )
                ->where( 'quantity', '<=', 0 )
                ->limit( $limit )
                ->get();

            foreach ( $empty as $row ) {
                $this->line( sprintf(
                    '  [%s] %s',
                    $row->product->sku ?? 'no-sku',
                    $row->product->name ?? 'unknown product'
                ) );
            }
        }

        return self::SUCCESS;
    }
}
