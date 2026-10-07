<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\ProductUnitQuantity;
use App\Models\Unit;
use App\Services\ProductService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Repairs the cost of goods on products sold in more than one unit.
 *
 * A cost only means something next to the unit it was measured in. A pack of 24 bought for 213.08 means a
 * bottle costs 8.88 — the same fact stated twice. The importers wrote the workbook's cost column onto
 * every unit row verbatim, so a bottle selling for 10 baht was recorded as costing 213.08 and the sales
 * report showed a 203 baht loss on a 10 baht sale.
 *
 * ProductService::spreadCogsAcrossUnits() now keeps this right whenever stock is received. This command is
 * for the rows that are already wrong, and it writes one column: cogs.
 *
 * Which unit is believed, in order:
 *   1. a unit carrying priced stock history — that is a measurement, and it wins
 *   2. failing that, the largest unit in the group, but only when every sibling holds an identical cost,
 *      which is the importer's fingerprint and means the figure is a purchase cost recorded per pack
 * Anything else is reported and left alone. A guessed cost is worse than a visibly wrong one, because
 * nobody goes looking for it.
 */
class FixUnitCogsCommand extends Command
{
    protected $signature = 'ns:fix-unit-cogs
                            {--dry-run : report what would change and write nothing}
                            {--product= : limit to one product id}';

    protected $description = 'Recalculates cost of goods per selling unit using the unit conversion ratio.';

    public function __construct( private ProductService $productService )
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option( 'dry-run' );

        /**
         * Grouped by branch as well as by product.
         *
         * Stock and prices live per branch on this table, so a product sold at two branches has a row per
         * unit per branch. Grouping by product alone treated all of them as one set and corrected whichever
         * branch the chosen source belonged to, leaving the other still holding the pack cost against a
         * single item — branch 1 of product 20883 read 49.08 per box while branch 2 read 589.
         */
        $groups = ProductUnitQuantity::selectRaw( 'product_id, branch_id, count(*) as rows_found' )
            ->when( $this->option( 'product' ), fn ( $query ) => $query->where( 'product_id', (int) $this->option( 'product' ) ) )
            ->groupBy( 'product_id', 'branch_id' )
            ->havingRaw( 'count(*) > 1' )
            ->get();

        $this->info( sprintf( 'Product/branch sets sold in more than one unit: %d', $groups->count() ) );

        if ( $groups->isEmpty() ) {
            return self::SUCCESS;
        }

        $corrected = 0;
        $alreadyRight = 0;
        $noBasis = 0;
        $examples = [];

        $bar = $this->output->createProgressBar( $groups->count() );
        $bar->start();

        foreach ( $groups->chunk( 200 ) as $chunk ) {
            DB::transaction( function () use ( $chunk, $dryRun, &$corrected, &$alreadyRight, &$noBasis, &$examples ): void {
                foreach ( $chunk as $group ) {
                    $productId = $group->product_id;
                    $branchId = $group->branch_id;

                    $rows = ProductUnitQuantity::where( 'product_id', $productId )
                        ->where( function ( $query ) use ( $branchId ) {
                            $branchId === null
                                ? $query->whereNull( 'branch_id' )
                                : $query->where( 'branch_id', $branchId );
                        } )
                        ->get();
                    $units = Unit::whereIn( 'id', $rows->pluck( 'unit_id' )->filter()->unique() )->get()->keyBy( 'id' );

                    $source = $this->chooseSource( $rows, $units, $branchId );

                    if ( ! $source instanceof ProductUnitQuantity ) {
                        $noBasis++;

                        continue;
                    }

                    $sourceUnit = $units[ $source->unit_id ];

                    foreach ( $rows as $row ) {
                        if ( $row->id === $source->id ) {
                            continue;
                        }

                        $unit = $units[ $row->unit_id ] ?? null;

                        if ( ! $unit instanceof Unit || (float) $unit->value <= 0 || $unit->group_id !== $sourceUnit->group_id ) {
                            continue;
                        }

                        $expected = round( (float) $source->cogs * (float) $unit->value / (float) $sourceUnit->value, 5 );

                        if ( abs( $expected - (float) $row->cogs ) < 0.00001 ) {
                            $alreadyRight++;

                            continue;
                        }

                        if ( count( $examples ) < 5 ) {
                            $examples[] = sprintf(
                                '%s [%s] %.5f -> %.5f  (from %s %.5f, ratio %s:%s)',
                                mb_substr( (string) ( Product::find( $productId )->name ?? $productId ), 0, 34 ),
                                $unit->name,
                                (float) $row->cogs,
                                $expected,
                                $sourceUnit->name,
                                (float) $source->cogs,
                                (float) $sourceUnit->value,
                                (float) $unit->value
                            );
                        }

                        if ( ! $dryRun ) {
                            /**
                             * One column by primary key. Not save() on the model: that fires the product
                             * events, which cascade into stock and search reindexing on a shop that is
                             * selling right now.
                             */
                            DB::table( ( new ProductUnitQuantity )->getTable() )
                                ->where( 'id', $row->id )
                                ->update( [ 'cogs' => $expected ] );
                        }

                        $corrected++;
                    }
                }
            } );

            $bar->advance( $chunk->count() );
        }

        $bar->finish();
        $this->newLine( 2 );

        $this->table(
            [ 'Outcome', 'Unit rows' ],
            [
                [ $dryRun ? 'Would be corrected' : 'Cost corrected', $corrected ],
                [ 'Already correct', $alreadyRight ],
                [ 'No trustworthy basis, left alone', $noBasis ],
            ]
        );

        if ( $examples !== [] ) {
            $this->newLine();
            $this->line( 'Examples:' );

            foreach ( $examples as $example ) {
                $this->line( '  ' . $example );
            }
        }

        if ( $noBasis > 0 ) {
            $this->newLine();
            $this->warn( sprintf(
                '%d products carry different costs per unit with no stock history to settle it. Nothing was',
                $noBasis
            ) );
            $this->warn( 'guessed for them — receive stock once and the cost will be derived from the delivery.' );
        }

        if ( $dryRun ) {
            $this->newLine();
            $this->warn( 'Dry run: nothing was written.' );
        }

        return self::SUCCESS;
    }

    /**
     * @param Collection<int, ProductUnitQuantity> $rows
     * @param Collection<int, Unit>                $units
     */
    private function chooseSource( $rows, $units, $branchId = null ): ?ProductUnitQuantity
    {
        $measured = $rows->first( function ( ProductUnitQuantity $row ) use ( $branchId ) {
            return (float) $row->cogs > 0 && ProductHistory::where( 'product_id', $row->product_id )
                ->where( 'unit_id', $row->unit_id )
                ->when(
                    $branchId !== null,
                    fn ( $query ) => $query->where( 'branch_id', $branchId )
                )
                ->whereIn( 'operation_type', [
                    ProductHistory::ACTION_CONVERT_IN,
                    ProductHistory::ACTION_STOCKED,
                ] )
                ->where( 'total_price', '>', 0 )
                ->exists();
        } );

        if ( $measured instanceof ProductUnitQuantity ) {
            return $measured;
        }

        /**
         * No delivery to learn from. The one remaining safe reading is the importer's fingerprint: every
         * unit holding the identical cost, which is a purchase cost recorded once per pack. The largest
         * unit in the group is the pack.
         */
        $costs = $rows->map( fn ( ProductUnitQuantity $row ) => round( (float) $row->cogs, 5 ) )->unique();

        if ( $costs->count() !== 1 || (float) $costs->first() <= 0 ) {
            return null;
        }

        return $rows->filter( fn ( ProductUnitQuantity $row ) => isset( $units[ $row->unit_id ] ) )
            ->sortByDesc( fn ( ProductUnitQuantity $row ) => (float) $units[ $row->unit_id ]->value )
            ->first();
    }
}
