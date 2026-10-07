<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * One branch's dashboard shows one branch's trade.
 *
 * Everything on this page used to be company-wide. The two cards at the top read a row of the
 * dashboard_days table, which has no branch column at all; the best-customers and best-cashiers panels read
 * running totals kept on the customer and the user, which are also company-wide; recent orders was the last
 * ten bills anywhere; and the per-branch takings panel — the one panel whose job is to compare branches —
 * showed every branch to a cashier who is only allowed in one.
 *
 * So a shop with two branches saw the same figures on both screens, and a branch manager saw the other
 * branch's takings. The customer's instruction was "ของใครของมัน บิลใครบิลมัน".
 *
 * The assertions are written as differences rather than as absolute figures. The shared fixture already
 * holds orders, and orders created before branches existed carry no branch — those are deliberately counted
 * everywhere, the same way branchless stock rows are, so that an install which was never split into
 * branches still reports its trade. Measuring the change an order makes is therefore the only way to say
 * something true about scoping.
 */
class DashboardBranchScopingTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    private User $admin;

    private int $branchA;

    private int $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->attemptGetAnyUserFromRole( 'admin' );

        /**
         * The administrator has no branch of their own, which is what lets the header picker move them
         * between branches. A user pinned to one is covered by its own test below.
         */
        $this->admin->branch_id = null;
        $this->admin->save();

        $this->attemptAuthenticate( $this->admin );

        $this->branchA = $this->givenBranch( 'สาขา A สำหรับทดสอบการแยกข้อมูล' );
        $this->branchB = $this->givenBranch( 'สาขา B สำหรับทดสอบการแยกข้อมูล' );
    }

    public function test_a_sale_in_one_branch_does_not_appear_in_the_other(): void
    {
        $beforeA = $this->cards( $this->branchA );
        $beforeB = $this->cards( $this->branchB );

        $this->givenPaidOrder( $this->branchB, 1234.0 );

        $afterA = $this->cards( $this->branchA );
        $afterB = $this->cards( $this->branchB );

        $this->assertEqualsWithDelta(
            $beforeB[ 'day_income' ] + 1234.0,
            $afterB[ 'day_income' ],
            0.01,
            'The branch that made the sale did not see it.'
        );

        $this->assertSame(
            $beforeB[ 'day_orders_count' ] + 1,
            $afterB[ 'day_orders_count' ],
            'The bill was not counted in the branch that rang it up.'
        );

        $this->assertEqualsWithDelta(
            $beforeA[ 'day_income' ],
            $afterA[ 'day_income' ],
            0.01,
            'A sale made in another branch was added to this one\'s takings.'
        );

        $this->assertSame(
            $beforeA[ 'day_orders_count' ],
            $afterA[ 'day_orders_count' ],
            'A bill from another branch was counted here.'
        );
    }

    /**
     * The count beside the amount is a number of bills. It used to print day_paid_orders, which is a sum
     * of money, so a day taking a few thousand baht reported a few thousand bills.
     */
    public function test_the_bill_count_is_a_count_and_not_an_amount(): void
    {
        $before = $this->cards( $this->branchA );

        $this->givenPaidOrder( $this->branchA, 5000.0 );

        $after = $this->cards( $this->branchA );

        $this->assertSame( $before[ 'day_orders_count' ] + 1, $after[ 'day_orders_count' ] );

        $this->assertLessThan(
            $after[ 'day_income' ],
            (float) $after[ 'day_orders_count' ],
            'The bill count is as large as the money taken, which is what printing an amount as a count did.'
        );
    }

    /**
     * The incomplete-orders card asked for total_unpaid and today_unpaid, and the table it was reading
     * carries neither, so it printed zero on every shop on every day.
     */
    public function test_money_still_owed_is_reported_and_only_to_its_own_branch(): void
    {
        $beforeA = $this->cards( $this->branchA );
        $beforeB = $this->cards( $this->branchB );

        $this->givenUnpaidOrder( $this->branchB, 800.0, tendered: 300.0 );

        $afterA = $this->cards( $this->branchA );
        $afterB = $this->cards( $this->branchB );

        /** 800 owed, 300 handed over, so 500 outstanding. */
        $this->assertEqualsWithDelta( $beforeB[ 'total_unpaid' ] + 500.0, $afterB[ 'total_unpaid' ], 0.01 );
        $this->assertEqualsWithDelta( $beforeB[ 'today_unpaid' ] + 500.0, $afterB[ 'today_unpaid' ], 0.01 );

        $this->assertEqualsWithDelta(
            $beforeA[ 'total_unpaid' ],
            $afterA[ 'total_unpaid' ],
            0.01,
            'Another branch\'s debt was shown here.'
        );
    }

    public function test_recent_orders_lists_only_this_branch(): void
    {
        $order = $this->givenPaidOrder( $this->branchB, 90.0 );

        $inB = collect( $this->endpoint( 'recent-orders', $this->branchB ) )->pluck( 'id' );
        $inA = collect( $this->endpoint( 'recent-orders', $this->branchA ) )->pluck( 'id' );

        $this->assertContains( $order->id, $inB );
        $this->assertNotContains( $order->id, $inA, 'A bill from another branch was listed here.' );
    }

    public function test_the_best_customers_panel_counts_only_what_was_spent_here(): void
    {
        $customerId = (int) Customer::value( 'id' );

        /** Large enough to reach the top five whatever the fixture already holds. */
        $this->givenPaidOrder( $this->branchB, 999999.0, customerId: $customerId );

        $spentInB = collect( $this->endpoint( 'best-customers', $this->branchB ) )
            ->firstWhere( 'id', $customerId );

        $this->assertNotNull( $spentInB, 'The buyer did not appear in the branch where they bought.' );

        $this->assertGreaterThanOrEqual(
            999999.0,
            (float) $spentInB[ 'purchases_amount' ],
            'The figure does not include the purchase made in this branch.'
        );

        $spentInA = collect( $this->endpoint( 'best-customers', $this->branchA ) )
            ->firstWhere( 'id', $customerId );

        if ( $spentInA !== null ) {
            $this->assertLessThan(
                999999.0,
                (float) $spentInA[ 'purchases_amount' ],
                'A purchase made in another branch was counted towards this one.'
            );
        }
    }

    public function test_the_best_cashiers_panel_counts_only_what_was_sold_here(): void
    {
        $this->givenPaidOrder( $this->branchB, 888888.0 );

        $soldInB = collect( $this->endpoint( 'best-cashiers', $this->branchB ) )
            ->firstWhere( 'id', $this->admin->id );

        $this->assertNotNull( $soldInB, 'The person who rang the sale up did not appear in that branch.' );

        $this->assertGreaterThanOrEqual( 888888.0, (float) $soldInB[ 'total_sales' ] );

        $soldInA = collect( $this->endpoint( 'best-cashiers', $this->branchA ) )
            ->firstWhere( 'id', $this->admin->id );

        if ( $soldInA !== null ) {
            $this->assertLessThan(
                888888.0,
                (float) $soldInA[ 'total_sales' ],
                'A sale made in another branch was credited here.'
            );
        }
    }

    public function test_the_takings_summary_follows_the_branch_too(): void
    {
        $beforeA = $this->salesSummary( $this->branchA );
        $beforeB = $this->salesSummary( $this->branchB );

        $this->givenPaidOrder( $this->branchB, 555.0 );

        $this->assertEqualsWithDelta(
            $beforeB[ 'income' ] + 555.0,
            $this->salesSummary( $this->branchB )[ 'income' ],
            0.01
        );

        $this->assertEqualsWithDelta(
            $beforeA[ 'income' ],
            $this->salesSummary( $this->branchA )[ 'income' ],
            0.01,
            'The takings panel added another branch\'s sale.'
        );
    }

    /**
     * A user who belongs to one branch is not shown the others' takings side by side.
     */
    public function test_a_user_tied_to_one_branch_sees_only_that_branch_in_the_comparison_panel(): void
    {
        $everyBranch = collect( $this->endpoint( 'branches-revenue', $this->branchA ) )->pluck( 'id' );

        $this->assertContains( $this->branchA, $everyBranch );
        $this->assertContains( $this->branchB, $everyBranch, 'The owner should still see every branch.' );

        $this->admin->branch_id = $this->branchB;
        $this->admin->save();

        $onlyTheirs = collect( $this->endpoint( 'branches-revenue', $this->branchB ) )->pluck( 'id' );

        $this->assertSame(
            [ $this->branchB ],
            $onlyTheirs->all(),
            'A user tied to one branch was shown other branches\' takings.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function cards( int $branchId ): array
    {
        return $this->endpoint( 'day', $branchId );
    }

    /**
     * @return array<string, mixed>
     */
    private function salesSummary( int $branchId ): array
    {
        return $this->endpoint( 'sales-summary?period=day', $branchId );
    }

    /**
     * @return array<mixed>
     */
    private function endpoint( string $path, int $branchId ): array
    {
        /**
         * The branch travels in the session, which is where SetDefaultBranch leaves it after validating it
         * against Branch::selectableBy on the web request that loaded the page. The api group does not run
         * that middleware, so a value put here is the value the endpoint reads — the same as a browser
         * sending its session cookie alongside the request.
         */
        $response = $this->withSession( [ 'selected_branch_id' => $branchId ] )
            ->getJson( '/api/dashboard/' . $path );

        $response->assertStatus( 200 );

        return $response->json();
    }

    private function givenBranch( string $name ): int
    {
        return (int) Branch::create( [
            'code' => 'SCOPE-' . uniqid(),
            'name' => $name,
            'is_active' => true,
            'is_main' => false,
            'author_id' => $this->admin->id,
        ] )->id;
    }

    private function givenPaidOrder( int $branchId, float $total, ?int $customerId = null ): Order
    {
        return $this->givenOrder( $branchId, $total, $total, Order::PAYMENT_PAID, $customerId );
    }

    private function givenUnpaidOrder( int $branchId, float $total, float $tendered ): Order
    {
        return $this->givenOrder(
            $branchId,
            $total,
            $tendered,
            $tendered > 0 ? Order::PAYMENT_PARTIALLY : Order::PAYMENT_UNPAID
        );
    }

    private function givenOrder( int $branchId, float $total, float $tendered, string $status, ?int $customerId = null ): Order
    {
        $order = new Order;
        $order->code = 'SCOPE-' . uniqid();
        $order->title = 'บิลสำหรับทดสอบการแยกสาขา';
        $order->type = Order::TYPE_TAKEAWAY;
        $order->payment_status = $status;
        $order->delivery_status = Order::PROCESSING_PENDING;
        $order->branch_id = $branchId;
        $order->customer_id = $customerId ?? (int) Customer::value( 'id' );
        $order->author_id = $this->admin->id;
        $order->subtotal = $total;
        $order->total = $total;
        $order->tendered = $tendered;
        $order->discount = 0;
        $order->created_at = now();
        $order->updated_at = now();
        $order->save();

        return $order;
    }
}
