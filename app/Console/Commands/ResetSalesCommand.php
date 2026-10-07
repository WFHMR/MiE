<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Procurement;
use App\Models\Register;
use App\Services\OrdersService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clears trading history so a shop can open on zero, while leaving the catalogue,
 * stock, taxes, units and settings exactly as they are.
 *
 * The built-in "ns:reset --mode=soft" cannot be used for this: its wipe list
 * includes nexopos_products, nexopos_products_unit_quantities, nexopos_taxes and
 * nexopos_units, so it would take the imported catalogue and the VAT configuration
 * with it.
 *
 * Orders are removed through OrdersService::deleteOrder() rather than by truncating
 * the table, because that is what returns the sold stock to the products and clears
 * the rows that hang off an order. Truncating first would leave the stock permanently
 * short by whatever had been sold during testing.
 */
class ResetSalesCommand extends Command
{
    protected $signature = 'pos:reset-sales
                            {--dry-run : Report what would be cleared without writing}
                            {--with-procurements : Also clear purchase records}
                            {--skip-backup : Do not take a database backup first}';

    protected $description = 'Clear sales history and reports, keeping products, stock and settings';

    /**
     * Aggregates and history that carry no information once the orders behind them
     * are gone. The dashboard reads these tables directly, which is why a figure
     * like "6,115 orders" can survive on screen when only one order exists.
     *
     * @var array<int, string>
     */
    private const HISTORY_TABLES = [
        'nexopos_dashboard_days',
        'nexopos_dashboard_weeks',
        'nexopos_dashboard_months',

        'nexopos_products_histories',
        'nexopos_products_histories_combined',

        'nexopos_registers_history',

        'nexopos_customers_account_history',
        'nexopos_customers_rewards',
        'nexopos_customers_coupons',

        'nexopos_transactions_histories',
        'nexopos_transactions_balance_days',
        'nexopos_transactions_balance_months',

        'nexopos_notifications',
    ];

    /**
     * Emptied by deleteOrder() already. Truncated as well so the auto-increment
     * restarts and the first real sale is order 1 rather than order 2.
     *
     * @var array<int, string>
     */
    private const ORDER_TABLES = [
        'nexopos_orders_addresses',
        'nexopos_orders_coupons',
        'nexopos_orders_metas',
        'nexopos_orders_payments',
        'nexopos_orders_products',
        'nexopos_orders_products_refunds',
        'nexopos_orders_refunds',
        'nexopos_orders_storage',
        'nexopos_orders_taxes',
        'nexopos_orders',
        'nexopos_orders_count',
    ];

    /**
     * @var array<int, string>
     */
    private const PROCUREMENT_TABLES = [
        'nexopos_procurements_products',
        'nexopos_procurements',
    ];

    public function handle( OrdersService $ordersService ): int
    {
        $dryRun = (bool) $this->option( 'dry-run' );
        $withProcurements = (bool) $this->option( 'with-procurements' );

        $this->report( 'Before', $withProcurements );

        if ( $dryRun ) {
            $this->newLine();
            $this->warn( '[dry run] Nothing was written.' );
            $this->newLine();
            $this->line( 'Would clear:' );
            $this->line( '  every order, and the rows attached to it' );
            $this->line( '  the daily, weekly and monthly dashboard aggregates' );
            $this->line( '  stock movement history' );
            $this->line( '  register sessions, customer statements, rewards and coupons' );
            $this->line( '  transaction history and balances' );

            if ( $withProcurements ) {
                $this->line( '  purchase records' );
            }

            $this->newLine();
            $this->line( 'Would keep:' );
            $this->line( '  products, stock on hand, prices, categories, units' );
            $this->line( '  taxes and tax groups, suppliers, customers, registers' );
            $this->line( '  users, roles, branches and every setting' );

            return self::SUCCESS;
        }

        if ( ! $this->option( 'skip-backup' ) ) {
            $this->newLine();
            $this->info( 'Taking a backup first' );

            if ( Artisan::call( 'db:backup', [ '--label' => 'pre-reset-sales' ] ) !== self::SUCCESS ) {
                $this->error( 'The backup failed, so nothing was cleared.' );
                $this->line( 'Fix the backup first, or re-run with --skip-backup to proceed without one.' );

                return self::FAILURE;
            }

            $this->line( trim( Artisan::output() ) );
        }

        $this->newLine();
        $this->info( 'Deleting orders and returning their stock' );

        $deleted = 0;
        $failed = 0;

        /**
         * Chunked rather than loaded at once: a till that has been trading for a
         * while can hold far more orders than fit comfortably in memory. Iterating
         * over ids because each delete changes the result of the query.
         */
        foreach ( Order::pluck( 'id' )->chunk( 50 ) as $ids ) {
            foreach ( $ids as $id ) {
                $order = Order::find( $id );

                if ( ! $order instanceof Order ) {
                    continue;
                }

                try {
                    $ordersService->deleteOrder( $order );
                    $deleted++;
                } catch ( \Throwable $exception ) {
                    $failed++;
                    $this->warn( sprintf( 'Order %d could not be deleted cleanly: %s', $id, $exception->getMessage() ) );
                }
            }
        }

        $this->line( sprintf( '  deleted %d order(s)', $deleted ) );

        if ( $failed > 0 ) {
            $this->warn( sprintf( '  %d order(s) failed, their rows are truncated below instead', $failed ) );
        }

        $tables = array_merge( self::ORDER_TABLES, self::HISTORY_TABLES );

        if ( $withProcurements ) {
            $tables = array_merge( $tables, self::PROCUREMENT_TABLES );
        }

        $this->newLine();
        $this->info( 'Clearing history and report tables' );

        /**
         * Truncate cannot run while a foreign key points at the table, and these
         * tables reference each other. Disabled around the whole batch rather than
         * per table so the order they are listed in does not matter.
         */
        Schema::disableForeignKeyConstraints();

        try {
            foreach ( $tables as $table ) {
                if ( ! Schema::hasTable( $table ) ) {
                    continue;
                }

                $count = DB::table( $table )->count();
                DB::table( $table )->truncate();

                if ( $count > 0 ) {
                    $this->line( sprintf( '  %-40s %d row(s) removed', $table, $count ) );
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->newLine();
        $this->info( 'Resetting counters' );

        // These are running totals kept on the customer, not derived on read, so
        // they survive the deletion of the orders that produced them.
        $customers = Customer::query()->update( [
            'purchases_amount' => 0,
            'owed_amount' => 0,
            'account_amount' => 0,
        ] );

        $this->line( sprintf( '  %d customer(s) reset to zero', $customers ) );

        if ( Schema::hasColumn( 'nexopos_registers', 'balance' ) ) {
            $registers = Register::query()->update( [
                'balance' => 0,
                'status' => Register::STATUS_CLOSED,
            ] );

            $this->line( sprintf( '  %d register(s) closed with a zero balance', $registers ) );
        }

        $this->newLine();
        $this->report( 'After', $withProcurements );

        $this->newLine();
        $this->info( 'Done. The shop can open on zero.' );
        $this->line( 'Clear the caches so the dashboard stops serving the old figures:' );
        $this->line( '  php artisan cache:clear' );

        return self::SUCCESS;
    }

    private function report( string $label, bool $withProcurements ): void
    {
        $this->info( $label );
        $this->line( sprintf( '  orders            : %d', Order::count() ) );
        $this->line( sprintf( '  products          : %d', DB::table( 'nexopos_products' )->count() ) );
        $this->line( sprintf( '  stock rows        : %d', DB::table( 'nexopos_products_unit_quantities' )->count() ) );

        foreach ( [ 'nexopos_dashboard_days', 'nexopos_products_histories' ] as $table ) {
            if ( Schema::hasTable( $table ) ) {
                $this->line( sprintf( '  %-17s : %d', str_replace( 'nexopos_', '', $table ), DB::table( $table )->count() ) );
            }
        }

        if ( $withProcurements && Schema::hasTable( 'nexopos_procurements' ) ) {
            $this->line( sprintf( '  procurements      : %d', Procurement::count() ) );
        }
    }
}
