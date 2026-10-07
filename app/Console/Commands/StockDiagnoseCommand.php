<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductUnitQuantity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * The till refuses a sale by product and unit, so a report that only shows a
 * product's first unit row cannot explain the refusal. This walks every unit row
 * and every duplicate of a product, which is where the zero usually hides.
 *
 * Writes to a UTF-8 file as well as the console, because the Windows console code
 * page renders Thai product names as mojibake and the names are the whole point.
 */
class StockDiagnoseCommand extends Command
{
    protected $signature = 'stock:diagnose
                            {search? : Part of a product name, SKU or barcode}
                            {--duplicates : Report products sharing a SKU or barcode instead}
                            {--limit=40 : Maximum groups to report}
                            {--out= : Also write the report to this UTF-8 file}';

    protected $description = 'Explain why a product cannot be sold, or list duplicated products';

    /**
     * @var array<int, string>
     */
    private array $lines = [];

    public function handle(): int
    {
        $limit = max( 1, (int) $this->option( 'limit' ) );

        if ( $this->option( 'duplicates' ) ) {
            $this->reportDuplicates( $limit );
        } else {
            $search = $this->argument( 'search' );

            if ( $search === null || trim( $search ) === '' ) {
                $this->error( 'Provide a search term, or pass --duplicates.' );

                return self::FAILURE;
            }

            $this->reportProducts( trim( $search ), $limit );
        }

        $out = $this->option( 'out' );

        if ( ! empty( $out ) ) {
            File::put( $out, implode( PHP_EOL, $this->lines ) . PHP_EOL );
            $this->info( 'Report written to ' . $out );
        }

        return self::SUCCESS;
    }

    private function reportDuplicates( int $limit ): void
    {
        foreach ( [ 'sku', 'barcode' ] as $column ) {
            $groups = Product::selectRaw( $column . ' as value, count(*) as aggregate' )
                ->whereNotNull( $column )
                ->where( $column, '!=', '' )
                ->groupBy( $column )
                ->havingRaw( 'count(*) > 1' )
                ->orderByDesc( 'aggregate' )
                ->limit( $limit )
                ->get();

            $totalGroups = DB::table( 'nexopos_products' )
                ->selectRaw( '1' )
                ->whereNotNull( $column )
                ->where( $column, '!=', '' )
                ->groupBy( $column )
                ->havingRaw( 'count(*) > 1' )
                ->get()
                ->count();

            $this->write( sprintf( 'Duplicated %s values: %d group(s)', $column, $totalGroups ) );

            $extraRows = 0;

            foreach ( $groups as $group ) {
                $extraRows += ( (int) $group->aggregate ) - 1;
            }

            if ( $totalGroups > 0 ) {
                $this->write( sprintf( '  showing the %d largest', $groups->count() ) );
            }

            foreach ( $groups as $group ) {
                $this->write( sprintf( '  %s = %s appears on %d products', $column, $group->value, $group->aggregate ) );

                $products = Product::where( $column, $group->value )
                    ->get( [ 'id', 'name', 'sku', 'barcode', 'status', 'stock_management' ] );

                foreach ( $products as $product ) {
                    $stock = ProductUnitQuantity::where( 'product_id', $product->id )->sum( 'quantity' );

                    $this->write( sprintf(
                        '    id=%d status=%s stock_total=%s name=%s',
                        $product->id,
                        $product->status,
                        $stock,
                        $product->name
                    ) );
                }
            }

            $this->write( '' );
        }
    }

    private function reportProducts( string $search, int $limit ): void
    {
        $products = Product::where( 'name', 'like', '%' . $search . '%' )
            ->orWhere( 'sku', 'like', '%' . $search . '%' )
            ->orWhere( 'barcode', 'like', '%' . $search . '%' )
            ->limit( $limit )
            ->get();

        if ( $products->isEmpty() ) {
            $this->write( 'No product matched "' . $search . '"' );

            return;
        }

        $this->write( sprintf( 'Matched %d product(s) for "%s"', $products->count(), $search ) );
        $this->write( '' );

        foreach ( $products as $product ) {
            $this->write( sprintf( '%s', $product->name ) );
            $this->write( sprintf(
                '  id=%d sku=%s barcode=%s status=%s stock_management=%s tax_group_id=%s tax_type=%s',
                $product->id,
                $product->sku,
                $product->barcode,
                $product->status,
                $product->stock_management,
                $product->tax_group_id ?? 'null',
                $product->tax_type ?? 'null'
            ) );

            $unitQuantities = ProductUnitQuantity::with( 'unit:id,name' )
                ->where( 'product_id', $product->id )
                ->get();

            if ( $unitQuantities->isEmpty() ) {
                $this->write( '  no unit quantity rows, so this product cannot be sold at all' );
                $this->write( '' );

                continue;
            }

            foreach ( $unitQuantities as $unitQuantity ) {
                $this->write( sprintf(
                    '  unit=%s (unit_id=%s) quantity=%s visible=%s sale_price=%s',
                    $unitQuantity->unit->name ?? 'unknown',
                    $unitQuantity->unit_id,
                    $unitQuantity->quantity,
                    $unitQuantity->visible ? 'yes' : 'no',
                    $unitQuantity->sale_price
                ) );
            }

            $this->write( '' );
        }
    }

    private function write( string $line ): void
    {
        $this->lines[] = $line;
        $this->line( $line );
    }
}
