<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Services\OrdersService;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * Accounts receivable, aged.
 *
 * Selling on credit is lending. Without a list of who owes what and for how long, a shop
 * finds out about a bad debt when the customer stops answering the phone. The orders screen
 * can filter unpaid orders but cannot answer the question that matters on a Monday morning:
 * which of these has gone stale, and who is the exposure.
 */
class ReceivablesAgingReportTest extends TestCase
{
    use WithAuthentication, WithFaker;

    private function createCreditCustomer( int $termDays ): Customer
    {
        $group = new CustomerGroup;
        $group->name = 'Aging ' . $termDays . ' ' . $this->faker->unique()->randomNumber( 5 );
        $group->description = 'Fixture';
        $group->reward_system_id = 0;
        $group->minimal_credit_payment = 0;
        $group->payment_term_days = $termDays;
        $group->author_id = 1;
        $group->save();

        $firstName = 'Aging' . $this->faker->unique()->randomNumber( 5 );

        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', 'api/crud/ns.customers', [
                'first_name' => $firstName,
                'general' => [
                    'group_id' => $group->id,
                    'last_name' => 'Receivable',
                    'email' => strtolower( $firstName ) . '@example.test',
                ],
            ] );

        $response->assertJson( [ 'status' => 'success' ] );

        return Customer::with( 'group' )->findOrFail( $response->json()[ 'data' ][ 'entry' ][ 'id' ] );
    }

    /**
     * @return array{unitQuantity: ProductUnitQuantity, payload: array<string,mixed>}
     */
    private function pickSellableProduct( float $quantity = 1 ): array
    {
        $product = Product::notGrouped()
            ->whereRelation( 'unit_quantities', 'quantity', '>', 100 )
            ->with( 'unit_quantities', fn( $query ) => $query->where( 'quantity', '>', 100 ) )
            ->firstOrFail();

        $unitQuantity = $product->unit_quantities->first();

        return [
            'unitQuantity' => $unitQuantity,
            'payload' => [
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => $unitQuantity->sale_price,
                'unit_quantity_id' => $unitQuantity->id,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function postOrder( Customer $customer, array $productPayload, array $overrides = [] )
    {
        $subtotal = ns()->currency->define( $productPayload[ 'unit_price' ] )
            ->multipliedBy( $productPayload[ 'quantity' ] )
            ->toFloat();

        return $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', 'api/orders', array_merge( [
                'customer_id' => $customer->id,
                'type' => [ 'identifier' => 'takeaway' ],
                'discount_type' => 'percentage',
                'discount_percentage' => 0,
                'addresses' => [],
                'coupons' => [],
                'subtotal' => $subtotal,
                'shipping' => 0,
                'total' => $subtotal,
                'tendered' => 0,
                'products' => [ $productPayload ],
                'payments' => [],
            ], $overrides ) );
    }

    /**
     * @return array<string,mixed>
     */
    private function loadReport()
    {
        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', '/api/reports/receivables' );

        $response->assertOk();

        return $response->json();
    }

    public function test_an_invoice_within_its_term_is_reported_as_not_yet_due(): void
    {
        $this->attemptAuthenticate();

        $customer = $this->createCreditCustomer( termDays: 30 );
        $selection = $this->pickSellableProduct();

        $response = $this->postOrder( $customer, $selection[ 'payload' ] );
        $response->assertJson( [ 'status' => 'success' ] );

        $order = Order::findOrFail( $response->json()[ 'data' ][ 'order' ][ 'id' ] );

        $report = $this->loadReport();

        $entry = collect( $report[ 'orders' ] )->firstWhere( 'id', $order->id );

        $this->assertNotNull( $entry, 'The unpaid credit order is missing from the receivables report.' );
        $this->assertEquals( 'not_due', $entry[ 'bucket' ] );
        $this->assertEquals( 0, $entry[ 'days_overdue' ] );
        $this->assertEquals(
            ns()->currency->define( $order->total )->toFloat(),
            ns()->currency->define( $entry[ 'outstanding' ] )->toFloat(),
            'The whole invoice is outstanding, so that is what should be reported.'
        );
    }

    /**
     * The bands are the point of the report. A total sitting in "over 90 days" is a
     * different problem from the same total sitting in "not due yet", and a report that
     * cannot tell them apart is just the unpaid orders list again.
     */
    public function test_an_overdue_invoice_lands_in_the_band_matching_its_age(): void
    {
        $this->attemptAuthenticate();

        $customer = $this->createCreditCustomer( termDays: 30 );

        $expectations = [
            10 => '1_30',
            45 => '31_60',
            75 => '61_90',
            200 => 'over_90',
        ];

        foreach ( $expectations as $daysLate => $expectedBucket ) {
            $selection = $this->pickSellableProduct();

            $response = $this->postOrder( $customer, $selection[ 'payload' ] );
            $response->assertJson( [ 'status' => 'success' ] );

            $order = Order::findOrFail( $response->json()[ 'data' ][ 'order' ][ 'id' ] );

            /**
             * The order is aged rather than the clock, so the rest of the fixture keeps
             * its own dates.
             */
            $order->final_payment_date = ns()->date->getNow()->subDays( $daysLate )->toDateTimeString();
            $order->save();

            $report = $this->loadReport();
            $entry = collect( $report[ 'orders' ] )->firstWhere( 'id', $order->id );

            $this->assertNotNull( $entry, 'An overdue invoice is missing from the report.' );

            $this->assertEquals(
                $expectedBucket,
                $entry[ 'bucket' ],
                sprintf( 'An invoice %d day(s) past due was not sorted into %s.', $daysLate, $expectedBucket )
            );

            $this->assertEquals(
                $daysLate,
                $entry[ 'days_overdue' ],
                'The reported age does not match how long the invoice has been overdue.'
            );
        }
    }

    /**
     * A layaway has no agreed date, so it cannot be aged. It still has to appear, otherwise
     * the report's total is not what the shop is owed.
     */
    public function test_an_unpaid_order_without_a_term_is_reported_separately(): void
    {
        $this->attemptAuthenticate();

        $customer = $this->createCreditCustomer( termDays: 0 );
        $selection = $this->pickSellableProduct();

        $response = $this->postOrder( $customer, $selection[ 'payload' ] );
        $response->assertJson( [ 'status' => 'success' ] );

        $order = Order::findOrFail( $response->json()[ 'data' ][ 'order' ][ 'id' ] );

        $this->assertNull( $order->final_payment_date );

        $report = $this->loadReport();
        $entry = collect( $report[ 'orders' ] )->firstWhere( 'id', $order->id );

        $this->assertNotNull( $entry, 'An unpaid order with no due date is missing from the report.' );
        $this->assertEquals( 'no_term', $entry[ 'bucket' ] );
    }

    /**
     * Only the unpaid remainder is a receivable. Reporting the invoice total would overstate
     * what the shop is owed by everything the customer has already handed over.
     */
    public function test_only_the_unpaid_remainder_is_reported(): void
    {
        $this->attemptAuthenticate();

        $customer = $this->createCreditCustomer( termDays: 30 );
        $selection = $this->pickSellableProduct( quantity: 2 );

        $total = ns()->currency->define( $selection[ 'payload' ][ 'unit_price' ] )
            ->multipliedBy( 2 )
            ->toFloat();

        $upFront = ns()->currency->define( $total )->dividedBy( 2 )->toFloat();

        $response = $this->postOrder( $customer, $selection[ 'payload' ], [
            'tendered' => $upFront,
            'payments' => [
                [
                    'identifier' => 'cash-payment',
                    'value' => $upFront,
                ],
            ],
        ] );

        $response->assertJson( [ 'status' => 'success' ] );

        $order = Order::findOrFail( $response->json()[ 'data' ][ 'order' ][ 'id' ] );

        $this->assertEquals( Order::PAYMENT_PARTIALLY, $order->payment_status );

        $report = $this->loadReport();
        $entry = collect( $report[ 'orders' ] )->firstWhere( 'id', $order->id );

        $this->assertNotNull( $entry, 'A partially paid order is missing from the receivables report.' );

        $this->assertEquals(
            ns()->currency->define( $order->total )->subtractBy( $order->tendered )->toFloat(),
            ns()->currency->define( $entry[ 'outstanding' ] )->toFloat(),
            'The report should show only what is still owed.'
        );
    }

    /**
     * A settled invoice is not a receivable.
     */
    public function test_a_settled_invoice_leaves_the_report(): void
    {
        $this->attemptAuthenticate();

        $customer = $this->createCreditCustomer( termDays: 30 );
        $selection = $this->pickSellableProduct();

        $response = $this->postOrder( $customer, $selection[ 'payload' ] );
        $response->assertJson( [ 'status' => 'success' ] );

        $order = Order::findOrFail( $response->json()[ 'data' ][ 'order' ][ 'id' ] );

        $this->assertNotNull(
            collect( $this->loadReport()[ 'orders' ] )->firstWhere( 'id', $order->id ),
            'The invoice should be listed before it is paid.'
        );

        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', 'api/orders/' . $order->id . '/payments', [
                'identifier' => 'cash-payment',
                'value' => $order->total,
            ] );

        $response->assertJson( [ 'status' => 'success' ] );

        $this->assertNull(
            collect( $this->loadReport()[ 'orders' ] )->firstWhere( 'id', $order->id ),
            'A fully paid invoice is no longer a receivable.'
        );
    }

    /**
     * The per-customer rows are how the shop decides who to call, so they have to add up to
     * the per-invoice detail and to the bands.
     */
    public function test_the_customer_rows_and_the_bands_agree_with_the_total(): void
    {
        $this->attemptAuthenticate();

        $customer = $this->createCreditCustomer( termDays: 30 );

        foreach ( [ 1, 2 ] as $quantity ) {
            $selection = $this->pickSellableProduct( quantity: $quantity );

            $this->postOrder( $customer, $selection[ 'payload' ] )
                ->assertJson( [ 'status' => 'success' ] );
        }

        $report = $this->loadReport();

        $ordersSum = ns()->currency->define(
            collect( $report[ 'orders' ] )->sum( 'outstanding' )
        )->toFloat();

        $customersSum = ns()->currency->define(
            collect( $report[ 'customers' ] )->sum( 'total' )
        )->toFloat();

        $bucketsSum = ns()->currency->define(
            collect( $report[ 'buckets' ] )->sum( 'total' )
        )->toFloat();

        $reportTotal = ns()->currency->define( $report[ 'total' ] )->toFloat();

        $this->assertEquals( $reportTotal, $ordersSum, 'The invoice detail does not add up to the reported total.' );
        $this->assertEquals( $reportTotal, $customersSum, 'The customer rows do not add up to the reported total.' );
        $this->assertEquals( $reportTotal, $bucketsSum, 'The age bands do not add up to the reported total.' );

        $row = collect( $report[ 'customers' ] )->firstWhere( 'customer_id', $customer->id );

        $this->assertNotNull( $row, 'The credit customer is missing from the per-customer summary.' );
        $this->assertEquals( 2, $row[ 'orders_count' ] );
        $this->assertEquals( 30, $row[ 'payment_term_days' ], 'The row should carry the term the customer buys on.' );
    }

    /**
     * The ageing can be measured from a chosen date so a shop can reproduce the position it
     * reported at a month end.
     */
    public function test_the_ageing_can_be_measured_from_a_past_date(): void
    {
        $this->attemptAuthenticate();

        $customer = $this->createCreditCustomer( termDays: 30 );
        $selection = $this->pickSellableProduct();

        $response = $this->postOrder( $customer, $selection[ 'payload' ] );
        $response->assertJson( [ 'status' => 'success' ] );

        $order = Order::findOrFail( $response->json()[ 'data' ][ 'order' ][ 'id' ] );

        /**
         * An invoice raised seventy days ago on thirty day terms, so it lapsed forty days
         * ago. The sale date is moved as well as the due date, because an as-of report must
         * not count an order that had not been placed yet.
         */
        $order->created_at = ns()->date->getNow()->subDays( 70 )->toDateTimeString();
        $order->final_payment_date = ns()->date->getNow()->subDays( 40 )->toDateTimeString();
        $order->save();

        /**
         * Measured from today the invoice is forty days late. Measured from a date five days
         * before it lapsed it was not late at all.
         */
        $service = app()->make( OrdersService::class );

        $today = $service->getReceivablesAging();
        $entry = collect( $today[ 'orders' ] )->firstWhere( 'id', $order->id );

        $this->assertEquals( '31_60', $entry[ 'bucket' ] );

        $earlier = $service->getReceivablesAging(
            ns()->date->getNow()->subDays( 45 )->toDateTimeString()
        );

        $entry = collect( $earlier[ 'orders' ] )->firstWhere( 'id', $order->id );

        $this->assertNotNull( $entry, 'The invoice existed at that date, so it should still be listed.' );
        $this->assertEquals( 'not_due', $entry[ 'bucket' ], 'The invoice had not lapsed at the chosen date.' );

        /**
         * And an order placed after the chosen date is not part of that position at all.
         */
        $beforeItExisted = $service->getReceivablesAging(
            ns()->date->getNow()->subDays( 90 )->toDateTimeString()
        );

        $this->assertNull(
            collect( $beforeItExisted[ 'orders' ] )->firstWhere( 'id', $order->id ),
            'An invoice raised after the chosen date should not appear in that position.'
        );
    }

    /**
     * The report is a management view and a cashier has no business seeing the shop's
     * exposure, so the route is gated.
     */
    public function test_the_report_route_requires_the_receivables_permission(): void
    {
        $this->attemptAuthenticate();

        $this->assertTrue(
            ns()->allowedTo( 'nexopos.reports.receivables' ),
            'The administrator should hold the receivables permission after the migration.'
        );

        $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', '/api/reports/receivables' )
            ->assertOk();
    }
}
