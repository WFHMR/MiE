<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The branch takings widget used to carry six branch names written into the Vue
 * component and put the whole day's income against the second one, leaving five
 * branches showing zero. That invented shops the business does not have, and it
 * hid the fact that the figure was never split by branch at all: a reader would
 * conclude five branches had a bad day rather than that the breakdown was fake.
 *
 * This endpoint replaces that with figures derived from the orders, so a branch
 * appears only once it exists and its total is genuinely its own.
 */
class BranchesRevenueEndpointTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        /**
         * The route is behind the "read.dashboard" permission, so it needs a user
         * that actually holds it. Resolved the same way ns:reset does.
         */
        $admin = Role::namespace( 'admin' )?->users()->first() ?? User::first();

        $this->actingAs( $admin );
    }

    public function test_it_returns_only_branches_that_exist(): void
    {
        if ( ! Schema::hasTable( 'nexopos_branches' ) ) {
            $this->markTestSkipped( 'No branches table on this install.' );
        }

        $expected = Branch::where( 'is_active', true )->count();

        $response = $this->getJson( '/api/dashboard/branches-revenue' );

        $response->assertOk();

        $this->assertCount(
            $expected,
            $response->json(),
            'The widget must show the branches the business actually has, no more and no fewer.'
        );
    }

    public function test_every_branch_reports_its_own_figures(): void
    {
        if ( ! Schema::hasTable( 'nexopos_branches' ) ) {
            $this->markTestSkipped( 'No branches table on this install.' );
        }

        $response = $this->getJson( '/api/dashboard/branches-revenue' );

        $response->assertOk();

        foreach ( $response->json() as $branch ) {
            $this->assertArrayHasKey( 'id', $branch );
            $this->assertArrayHasKey( 'name', $branch );
            $this->assertArrayHasKey( 'revenue', $branch );
            $this->assertArrayHasKey( 'orders', $branch );

            $this->assertIsNumeric( $branch[ 'revenue' ] );
            $this->assertIsNumeric( $branch[ 'orders' ] );
        }
    }

    public function test_the_total_it_reports_never_exceeds_the_takings_recorded_today(): void
    {
        if ( ! Schema::hasTable( 'nexopos_branches' ) ) {
            $this->markTestSkipped( 'No branches table on this install.' );
        }

        $response = $this->getJson( '/api/dashboard/branches-revenue' );
        $response->assertOk();

        $reported = collect( $response->json() )->sum( 'revenue' );

        $actual = Order::whereBetween( 'created_at', [
            now()->startOfDay()->toDateTimeString(),
            now()->endOfDay()->toDateTimeString(),
        ] )
            ->whereIn( 'payment_status', [ Order::PAYMENT_PAID, Order::PAYMENT_PARTIALLY ] )
            ->sum( 'total' );

        /**
         * The old widget failed exactly here: it reported the whole day against one
         * branch whether or not any order belonged to it.
         */
        $this->assertLessThanOrEqual(
            round( (float) $actual, 2 ) + 0.01,
            round( (float) $reported, 2 ),
            'Branch takings must add up to no more than what was actually taken today.'
        );
    }

    public function test_it_excludes_branches_that_have_been_deactivated(): void
    {
        if ( ! Schema::hasTable( 'nexopos_branches' ) ) {
            $this->markTestSkipped( 'No branches table on this install.' );
        }

        /**
         * Created directly rather than through a factory: there is no BranchFactory,
         * and code is unique so it has to be distinct per run.
         */
        $branch = Branch::create( [
            'code' => 'TEST-' . uniqid(),
            'name' => 'Branch taken out of use',
            'is_active' => true,
            'is_main' => false,
            'author_id' => auth()->id() ?? 1,
        ] );

        $this->assertContains(
            $branch->id,
            collect( $this->getJson( '/api/dashboard/branches-revenue' )->json() )->pluck( 'id' )->all(),
            'An active branch should be listed before it is deactivated, otherwise this proves nothing.'
        );

        $branch->is_active = false;
        $branch->save();

        $response = $this->getJson( '/api/dashboard/branches-revenue' );
        $response->assertOk();

        $this->assertNotContains(
            $branch->id,
            collect( $response->json() )->pluck( 'id' )->all(),
            'A branch taken out of use must not sit on the dashboard reporting zero.'
        );
    }
}
