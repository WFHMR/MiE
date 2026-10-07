<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Opening a shop on zero has to clear the trading history without touching the
 * catalogue. The built-in "ns:reset --mode=soft" cannot be used for it, because its
 * wipe list includes nexopos_products, nexopos_products_unit_quantities,
 * nexopos_taxes and nexopos_units: running it would delete the imported catalogue
 * and the VAT configuration along with the sales.
 *
 * These cover the line between the two, in both directions.
 */
class ResetSalesCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_dry_run_changes_nothing(): void
    {
        $ordersBefore = Order::count();
        $productsBefore = Product::count();

        $this->artisan( 'pos:reset-sales', [ '--dry-run' => true ] )
            ->assertSuccessful();

        $this->assertSame( $ordersBefore, Order::count() );
        $this->assertSame( $productsBefore, Product::count() );
    }

    public function test_it_clears_orders_and_dashboard_aggregates(): void
    {
        /**
         * The dashboard reads these aggregates directly rather than deriving them,
         * which is how a figure of thousands of orders stays on screen when the
         * orders table holds one row.
         */
        if ( Schema::hasTable( 'nexopos_dashboard_days' ) ) {
            DB::table( 'nexopos_dashboard_days' )->insert( [ 'day_of_year' => 1 ] );

            $this->assertGreaterThan( 0, DB::table( 'nexopos_dashboard_days' )->count() );
        }

        $this->artisan( 'pos:reset-sales', [ '--skip-backup' => true ] )
            ->assertSuccessful();

        $this->assertSame( 0, Order::count() );

        if ( Schema::hasTable( 'nexopos_dashboard_days' ) ) {
            $this->assertSame( 0, DB::table( 'nexopos_dashboard_days' )->count() );
        }
    }

    public function test_it_keeps_the_catalogue_and_the_stock_on_hand(): void
    {
        $productsBefore = Product::count();
        $stockRowsBefore = ProductUnitQuantity::count();
        $stockOnHandBefore = (float) ProductUnitQuantity::sum( 'quantity' );

        $this->assertGreaterThan( 0, $productsBefore, 'The fixture needs products for this to prove anything.' );

        $this->artisan( 'pos:reset-sales', [ '--skip-backup' => true ] )
            ->assertSuccessful();

        $this->assertSame(
            $productsBefore,
            Product::count(),
            'Clearing sales must not remove products.'
        );

        $this->assertSame(
            $stockRowsBefore,
            ProductUnitQuantity::count(),
            'Clearing sales must not remove stock rows.'
        );

        $this->assertGreaterThanOrEqual(
            $stockOnHandBefore,
            (float) ProductUnitQuantity::sum( 'quantity' ),
            'Deleting an order returns its stock, so the total on hand can only stay the same or rise.'
        );
    }

    public function test_it_keeps_taxes_and_units( ): void
    {
        $taxes = DB::table( 'nexopos_taxes' )->count();
        $units = DB::table( 'nexopos_units' )->count();

        $this->artisan( 'pos:reset-sales', [ '--skip-backup' => true ] )
            ->assertSuccessful();

        $this->assertSame( $taxes, DB::table( 'nexopos_taxes' )->count(), 'VAT configuration must survive.' );
        $this->assertSame( $units, DB::table( 'nexopos_units' )->count(), 'Units must survive.' );
    }

    public function test_it_leaves_procurements_alone_unless_asked(): void
    {
        if ( ! Schema::hasTable( 'nexopos_procurements' ) ) {
            $this->markTestSkipped( 'No procurements table on this install.' );
        }

        $before = DB::table( 'nexopos_procurements' )->count();

        $this->artisan( 'pos:reset-sales', [ '--skip-backup' => true ] )
            ->assertSuccessful();

        $this->assertSame(
            $before,
            DB::table( 'nexopos_procurements' )->count(),
            'Purchases are a separate decision from sales and must not be cleared by default.'
        );
    }

    public function test_it_zeroes_the_running_totals_kept_on_customers(): void
    {
        /**
         * Customers live in the users table in this application, which is worth
         * stating because a reset written against a "customers" table would fail
         * with "no such table" and take the whole command down with it.
         */
        Customer::query()->update( [ 'purchases_amount' => 500 ] );

        $this->assertGreaterThan( 0, Customer::where( 'purchases_amount', '>', 0 )->count() );

        $this->artisan( 'pos:reset-sales', [ '--skip-backup' => true ] )
            ->assertSuccessful();

        $this->assertSame(
            0,
            Customer::where( 'purchases_amount', '>', 0 )->count(),
            'These totals are stored, not derived, so they survive deleting the orders behind them.'
        );
    }
}
