<?php

namespace App\Console\Commands;

use App\Models\ProductUnitQuantity;
use App\Services\ProductService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * ProductService::getQuantity() reads stock filtered by the current branch, while
 * the check in OrdersService reads the unit quantity row directly. When the rows
 * carry no branch the two disagree: the order passes validation and then the stock
 * adjustment reports zero on hand and refuses the sale as negative stock.
 *
 * This reports the branch column as it actually is, so that mismatch can be seen
 * rather than inferred from an error message.
 */
class DiagnoseBranchStockCommand extends Command
{
    protected $signature = 'stock:diagnose-branch';

    protected $description = 'Report how stock rows are distributed across branches';

    public function handle(): int
    {
        $this->info( 'Multi store setting' );
        $this->line( '  ns_store_enabled = ' . ( ns()->option->get( 'ns_store_enabled', 'no' ) ?: 'not set' ) );
        $this->newLine();

        if ( ! Schema::hasColumn( 'nexopos_products_unit_quantities', 'branch_id' ) ) {
            $this->warn( 'The unit quantities table has no branch_id column, so branch filtering cannot apply.' );

            return self::SUCCESS;
        }

        $this->info( 'Stock rows by branch_id' );

        $distribution = ProductUnitQuantity::selectRaw( 'branch_id, count(*) as aggregate, sum(quantity) as total' )
            ->groupBy( 'branch_id' )
            ->get();

        foreach ( $distribution as $row ) {
            $this->line( sprintf(
                '  branch_id=%s : %d row(s), total quantity %s',
                $row->branch_id === null ? 'NULL' : $row->branch_id,
                $row->aggregate,
                $row->total
            ) );
        }

        $this->newLine();

        $nullRows = ProductUnitQuantity::whereNull( 'branch_id' )->count();

        if ( $nullRows > 0 && ns()->option->get( 'ns_store_enabled', 'no' ) === 'yes' ) {
            $this->warn( sprintf(
                'Multi store is on and %d stock row(s) have no branch, so getQuantity() will read them as zero.',
                $nullRows
            ) );
        }

        $this->readBackThroughTheService();

        return self::SUCCESS;
    }

    /**
     * The stock adjustment reads through ProductService, not straight off the row,
     * so comparing the two is what shows whether a sale will be allowed. A branch
     * is put in the session first because that is what a logged in cashier has.
     */
    private function readBackThroughTheService(): void
    {
        $row = ProductUnitQuantity::where( 'quantity', '>', 0 )->first();

        if ( $row === null ) {
            $this->warn( 'No stock row with a positive quantity to check against.' );

            return;
        }

        session( [ 'selected_branch_id' => 1 ] );

        $service = app( ProductService::class );
        $readBack = $service->getQuantity( $row->product_id, $row->unit_id );

        $this->newLine();
        $this->info( 'Reading one product back through ProductService' );
        $this->line( sprintf( '  session selected_branch_id = 1' ) );
        $this->line( sprintf( '  product_id=%d unit_id=%d', $row->product_id, $row->unit_id ) );
        $this->line( sprintf( '  quantity on the row       = %s', $row->quantity ) );
        $this->line( sprintf( '  getQuantity() returns     = %s', $readBack ) );
        $this->newLine();

        if ( abs( ( (float) $row->quantity ) - ( (float) $readBack ) ) > 0.00001 ) {
            $this->error( 'The two disagree, so the till will refuse this sale as negative stock.' );

            return;
        }

        $this->info( 'The two agree, so the stock adjustment will allow the sale.' );
    }
}
