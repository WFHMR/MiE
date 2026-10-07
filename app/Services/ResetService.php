<?php

namespace App\Services;

use App\Classes\Hook;
use App\Classes\Schema;
use App\Events\AfterHardResetEvent;
use App\Events\BeforeHardResetEvent;
use App\Models\Customer;
use App\Models\Option;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResetService
{
    public function softReset()
    {
        $tables = Hook::filter( 'ns-wipeable-tables', [
            'nexopos_coupons',
            'nexopos_coupons_products',
            'nexopos_coupons_categories',
            'nexopos_coupons_customers',
            'nexopos_coupons_customers_groups',

            'nexopos_customers_account_history',
            'nexopos_customers_addresses',
            'nexopos_customers_coupons',
            'nexopos_customers_groups',
            'nexopos_customers_rewards',

            'nexopos_dashboard_days',
            'nexopos_dashboard_weeks',
            'nexopos_dashboard_months',

            'nexopos_transactions',
            'nexopos_transactions_accounts',
            'nexopos_transactions_histories',
            'nexopos_transactions_balance_days',
            'nexopos_transactions_balance_months',
            'nexopos_transactions_actions_rules',

            'nexopos_medias',
            'nexopos_notifications',

            'nexopos_orders',
            'nexopos_orders_addresses',
            'nexopos_orders_count',
            'nexopos_orders_coupons',
            'nexopos_orders_metas',
            'nexopos_orders_payments',
            'nexopos_orders_products',
            'nexopos_orders_products_refunds',
            'nexopos_orders_refunds',
            'nexopos_orders_storage',
            'nexopos_orders_taxes',

            'nexopos_procurements',
            'nexopos_procurements_products',

            'nexopos_products',
            'nexopos_products_categories',
            'nexopos_products_histories',
            'nexopos_products_histories_combined',
            'nexopos_products_galleries',
            'nexopos_products_metas',
            'nexopos_products_taxes',
            'nexopos_products_unit_quantities',

            'nexopos_payments_types',

            'nexopos_providers',

            'nexopos_registers',
            'nexopos_registers_history',

            'nexopos_rewards_system',
            'nexopos_rewards_system_rules',

            'nexopos_taxes',
            'nexopos_taxes_groups',

            'nexopos_units',
            'nexopos_units_groups',
        ] );

        foreach ( $tables as $table ) {
            if ( Hook::filter( 'ns-reset-table', $table ) !== false && Schema::hasTable( Hook::filter( 'ns-reset-table', $table ) ) ) {
                DB::table( Hook::filter( 'ns-table-name', $table ) )->truncate();
            }
        }

        /**
         * @var CustomerService $customerService
         */
        $customerService = app()->make( CustomerService::class );

        /**
         * Customers stills needs to be cleared
         * so we'll remove them manually.
         */
        Customer::get()->each( fn( $customer ) => $customerService->delete( $customer ) );

        /**
         * We'll delete all options where key starts with "ns_"
         * as this is a reserved key for the system, we can safely delete it
         * but excluding some options provided in an array
         */
        Option::where( 'key', 'LIKE', 'ns\_%' )
            ->where( 'key', 'NOT LIKE', 'ns\_pa_%' )
            ->where( 'key', 'NOT LIKE', 'ns\_gastro_%' )
            ->where( 'key', 'NOT LIKE', 'ns\_sms_%' )
            ->where( 'key', 'NOT LIKE', 'ns\_email_%' )
            ->where( 'key', 'NOT LIKE', 'ns-stocktransfers%' )
            ->whereNotIn( 'key', [
                'ns_store_name',
                'ns_store_email',
                'ns_date_format',
                'ns_datetime_format',
                'ns_currency_precision',
                'ns_currency_iso',
                'ns_currency_symbol',
                'enabled_modules',
                'ns_pos_order_types',
            ] )->delete();

        return [
            'status' => 'success',
            'message' => __( 'The table has been truncated.' ),
        ];
    }

    /**
     * Will completely wipe the database
     * forcing a new installation to be made
     */
    public function hardReset(): array
    {
        BeforeHardResetEvent::dispatch();

        /**
         * this will only apply clearing all tables
         * when we're not using sqlite.
         */
        if ( DB::connection()->getDriverName() !== 'sqlite' ) {
            $tables = DB::select( 'SHOW TABLES' );

            foreach ( $tables as $table ) {
                $table_name = array_values( (array) $table )[0];
                DB::statement( 'SET FOREIGN_KEY_CHECKS = 0' );
                DB::statement( "DROP TABLE `$table_name`" );
                DB::statement( 'SET FOREIGN_KEY_CHECKS = 1' );
            }
        } else {
            $this->truncateSqliteDatabase();
        }

        /**
         * Rotating the application key and the session cookie name invalidates
         * every existing session. That is the point after a hard reset, but it is
         * pure collateral damage during a test run: the suite would rewrite the
         * developer's .env and log them out of their own browser, which surfaces
         * as "Unauthenticated." on every dashboard request.
         *
         * The comparison is case insensitive on purpose. Laravel matches
         * environment names case sensitively, and the phpunit configuration used
         * to declare APP_ENV as "TESTING", which made runningUnitTests() answer
         * false and defeated this very guard.
         */
        if ( ! $this->runningTests() ) {
            Artisan::call( 'key:generate', [ '--force' => true ] );
            Artisan::call( 'ns:cookie', [ 'action' => 'generate' ] );
        }

        $this->removePublicStorageLink();

        AfterHardResetEvent::dispatch();

        return [
            'status' => 'success',
            'message' => __( 'The database has been wiped out.' ),
        ];
    }

    /**
     * Whether the current process is a test run, regardless of how APP_ENV is
     * capitalised.
     */
    private function runningTests(): bool
    {
        return app()->runningUnitTests()
            || strcasecmp( (string) app()->environment(), 'testing' ) === 0;
    }

    /**
     * Empties the sqlite file the application is actually connected to.
     *
     * The previous implementation always truncated database/database.sqlite, so a
     * reset left the configured database untouched. Under the test suite that
     * meant the fixture kept state across runs, which is how a register left open
     * by CashRegisterActionsTest ended up breaking an unrelated route sweep.
     */
    private function truncateSqliteDatabase(): void
    {
        $database = DB::connection()->getDatabaseName();

        if ( in_array( $database, [ '', ':memory:' ], true ) ) {
            return;
        }

        $path = str_starts_with( $database, DIRECTORY_SEPARATOR ) || preg_match( '/^[A-Za-z]:/', $database )
            ? $database
            : base_path( $database );

        DB::disconnect();

        file_put_contents( $path, '' );
    }

    /**
     * Removes the public/storage link so that the next storage:link recreates it.
     *
     * Only the link itself is removed, never its target. public/storage points at
     * storage/app/public, which holds every uploaded media file, so a recursive
     * delete would follow the link and destroy real data. The previous
     * implementation shelled out to "rm -rf public/storage", which silently did
     * nothing on Windows and left the stale link in place.
     */
    private function removePublicStorageLink(): void
    {
        $link = public_path( 'storage' );

        if ( ! file_exists( $link ) && ! is_link( $link ) ) {
            return;
        }

        /**
         * Unix symlinks and Windows file symlinks are removed with unlink().
         * Windows directory junctions and directory symlinks need rmdir(), which
         * also leaves the target untouched.
         */
        if ( is_link( $link ) && @unlink( $link ) ) {
            return;
        }

        if ( is_dir( $link ) && @rmdir( $link ) ) {
            return;
        }

        Log::warning( sprintf( 'Unable to remove the storage link at "%s".', $link ) );
    }

    public function handleCustom( $data )
    {
        /**
         * @var string $mode
         * @var bool   $create_sales
         * @var bool   $create_procurements
         */
        extract( $data );

        return Hook::filter( 'ns-handle-custom-reset', [
            'status' => 'error',
            'message' => __( 'No custom handler for the reset "' . $mode . '"' ),
        ], $data );
    }
}
