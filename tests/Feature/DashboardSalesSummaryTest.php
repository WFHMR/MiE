<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * The takings panel on the dashboard: today, this month, this year.
 *
 * It had no endpoint. All three tabs called /api/dashboard/day, which answers a single row of the
 * dashboard_days table, and read fields off it that the table does not have — total_orders,
 * total_customers, day_orders, day_customers — so the two counts beside the amount were always zero. The
 * day tab printed day_paid_orders as the number of bills, and that column holds an amount of money. The
 * month and year tabs both read total_income, which ReportService maintains as a running sum of every day
 * since the shop opened, so both showed the same all-time figure and neither was a month or a year.
 *
 * What is pinned here is the part that cost the shop something: that the three periods answer three
 * different windows, that the counts are counts, and that the year is not the whole ledger.
 */
class DashboardSalesSummaryTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->attemptGetAnyUserFromRole( 'admin' );

        $this->attemptAuthenticate( $this->admin );
    }

    public function test_each_period_counts_only_the_orders_inside_it(): void
    {
        $today = $this->givenPaidOrder( 100.0, now() );
        $earlierThisMonth = $this->givenPaidOrder( 200.0, now()->startOfMonth()->addHours( 3 ) );
        $earlierThisYear = $this->givenPaidOrder( 400.0, now()->startOfYear()->addHours( 3 ) );
        $lastYear = $this->givenPaidOrder( 800.0, now()->subYear()->startOfYear()->addHours( 3 ) );

        $day = $this->summary( 'day' );
        $month = $this->summary( 'month' );
        $year = $this->summary( 'year' );

        /**
         * Each window contains the ones before it and excludes what falls outside. Compared as "contains
         * at least", because the shared fixture may already hold orders of its own.
         */
        $this->assertGreaterThanOrEqual( 100.0, $day[ 'income' ] );
        $this->assertGreaterThanOrEqual( $day[ 'income' ], $month[ 'income' ] );
        $this->assertGreaterThanOrEqual( $month[ 'income' ], $year[ 'income' ] );

        /** The order placed a year ago belongs to none of the three windows. */
        $this->assertNotContains( $lastYear->id, $this->idsInsideThePeriod( 'year' ) );

        $this->assertContains( $today->id, $this->idsInsideThePeriod( 'day' ) );
        $this->assertContains( $earlierThisMonth->id, $this->idsInsideThePeriod( 'month' ) );
        $this->assertContains( $earlierThisYear->id, $this->idsInsideThePeriod( 'year' ) );

        $this->assertNotContains( $earlierThisYear->id, $this->idsInsideThePeriod( 'day' ) );
    }

    /**
     * The month and the year answered the same number before, because both read a cumulative column.
     */
    public function test_the_month_and_the_year_are_not_the_same_figure(): void
    {
        $this->givenPaidOrder( 5000.0, now()->startOfYear()->addDay() );
        $this->givenPaidOrder( 70.0, now() );

        $month = $this->summary( 'month' );
        $year = $this->summary( 'year' );

        /**
         * Skipped in January, when the two windows genuinely coincide and the assertion would prove
         * nothing either way.
         */
        if ( now()->month === 1 ) {
            $this->markTestSkipped( 'In January the month and the year are the same window.' );
        }

        $this->assertNotEqualsWithDelta(
            $month[ 'income' ],
            $year[ 'income' ],
            0.01,
            'The month and the year report the same takings, which is what reading a cumulative column did.'
        );
    }

    public function test_the_counts_are_counts_and_not_amounts_of_money(): void
    {
        $this->givenPaidOrder( 12345.0, now() );

        $day = $this->summary( 'day' );

        $this->assertIsInt( $day[ 'orders' ] );
        $this->assertIsInt( $day[ 'customers' ] );

        /**
         * The old widget printed day_paid_orders, an amount, in the Orders box. A shop taking twelve
         * thousand baht over a handful of bills would have read "12345" bills.
         */
        $this->assertLessThan(
            $day[ 'income' ],
            (float) $day[ 'orders' ],
            'The number of bills is as large as the money taken, which is what printing an amount as a count did.'
        );
    }

    public function test_unpaid_orders_are_not_counted_as_takings(): void
    {
        $before = $this->summary( 'day' );

        $unpaid = $this->givenPaidOrder( 999.0, now() );
        $unpaid->payment_status = Order::PAYMENT_UNPAID;
        $unpaid->save();

        $after = $this->summary( 'day' );

        $this->assertEqualsWithDelta(
            $before[ 'income' ],
            $after[ 'income' ],
            0.01,
            'An unpaid order was added to the takings.'
        );
    }

    /**
     * Two bills from the same buyer are two bills and one customer. Counting rows would have made the
     * Customers box a second, slightly different copy of the Orders box.
     */
    public function test_the_same_customer_buying_twice_counts_once(): void
    {
        $customerId = (int) Customer::value( 'id' );

        $before = $this->summary( 'day' );

        $this->givenPaidOrder( 60.0, now(), customerId: $customerId );
        $this->givenPaidOrder( 70.0, now(), customerId: $customerId );

        $after = $this->summary( 'day' );

        $this->assertSame(
            $before[ 'orders' ] + 2,
            $after[ 'orders' ],
            'Two bills were not counted as two.'
        );

        $this->assertLessThanOrEqual(
            $before[ 'customers' ] + 1,
            $after[ 'customers' ],
            'One buyer placing two orders was counted as two customers.'
        );
    }

    public function test_an_unknown_period_answers_the_day_rather_than_failing(): void
    {
        $response = $this->getJson( '/api/dashboard/sales-summary?period=fortnight' );

        $response->assertStatus( 200 );
        $response->assertJson( [ 'period' => 'day' ] );
    }

    /**
     * The component asks this endpoint and nothing else. Read from the source rather than asserted through
     * the browser, because the fault it replaces was exactly a component reading fields off the wrong
     * endpoint and no test noticing.
     */
    public function test_the_widget_asks_this_endpoint_for_every_tab(): void
    {
        $component = File::get( resource_path( 'ts/widgets/ns-my-nexopos-widget.vue' ) );

        $this->assertStringContainsString( '/api/dashboard/sales-summary?period=', $component );

        $this->assertStringNotContainsString(
            '/api/dashboard/day',
            $component,
            'The widget still asks the single-day report, which is where the wrong figures came from.'
        );

        $this->assertSame(
            1,
            substr_count( $component, 'nsHttpClient.get(' ),
            'The widget makes more than one request, which is how its three tabs came to disagree.'
        );

        /**
         * Matched as field accesses rather than as bare words, so the explanation written above the method
         * in the component — which names the fields it used to read — does not fail its own test.
         */
        foreach ( [ 'total_income', 'day_paid_orders', 'total_orders', 'total_customers', 'day_customers', 'day_income' ] as $field ) {
            $this->assertStringNotContainsString(
                'report.' . $field,
                $component,
                sprintf( 'The widget still reads report.%s off the day report.', $field )
            );
        }

        foreach ( [ 'summary.income', 'summary.orders', 'summary.customers' ] as $read ) {
            $this->assertStringContainsString( $read, $component );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summary( string $period ): array
    {
        $response = $this->getJson( '/api/dashboard/sales-summary?period=' . $period );

        $response->assertStatus( 200 );

        return $response->json();
    }

    /**
     * The ids the endpoint's own window covers, read back through the same rule so the assertions above
     * describe the endpoint rather than a second copy of its arithmetic.
     *
     * @return array<int, int>
     */
    private function idsInsideThePeriod( string $period ): array
    {
        $summary = $this->summary( $period );

        return Order::from( $summary[ 'range_starts' ] )
            ->to( $summary[ 'range_ends' ] )
            ->whereIn( 'payment_status', [ Order::PAYMENT_PAID, Order::PAYMENT_PARTIALLY ] )
            ->pluck( 'id' )
            ->all();
    }

    private function givenPaidOrder( float $total, $createdAt, ?int $customerId = null ): Order
    {
        $order = new Order;
        $order->code = 'SUM-' . uniqid();
        $order->title = 'คำสั่งซื้อสำหรับทดสอบสรุปยอด';
        $order->payment_status = Order::PAYMENT_PAID;
        $order->delivery_status = Order::PROCESSING_PENDING;
        $order->type = Order::TYPE_TAKEAWAY;
        /** customer_id is NOT NULL on this table, so every bill carries a buyer. */
        $order->customer_id = $customerId ?? (int) Customer::value( 'id' );
        $order->author_id = $this->admin->id;
        $order->subtotal = $total;
        $order->total = $total;
        $order->tendered = $total;
        $order->discount = 0;
        $order->created_at = $createdAt;
        $order->updated_at = $createdAt;
        $order->save();

        return $order;
    }
}
