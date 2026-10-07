<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Order;
use App\Models\OrderInstalment;
use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\ProductUnitQuantity;
use App\Models\TransactionActionRule;
use App\Models\TransactionHistory;
use App\Services\OrdersService;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * Credit terms, the trade counterpart of a cash sale.
 *
 * A customer whose group grants a term takes the goods now and settles the invoice
 * within the agreed number of days. Two things have to hold for that to be usable:
 * the stock must leave the shelf at the moment of the sale, and the order must carry a
 * due date so it turns overdue on its own.
 *
 * Both were broken before. An unpaid order deducted nothing, because the stock listener
 * only reacted to paid and partially paid orders, so the shop kept counting goods that
 * had already been handed over. And "Save As Unpaid" posted no schedule at all, so the
 * order never became due no matter how long it went unsettled.
 */
class CreditTermOrderTest extends TestCase
{
    use WithAuthentication, WithFaker;

    /**
     * Creates a customer group along with one customer belonging to it.
     *
     * The group is created through the CRUD endpoint on purpose: it proves the new field
     * is actually accepted and persisted by the form, not merely readable on the model.
     */
    private function createCreditCustomer( int $termDays, float $minimalCreditPayment = 0 ): Customer
    {
        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', 'api/crud/ns.customers-groups', [
                'name' => 'Credit ' . $termDays . ' Days ' . $this->faker->unique()->randomNumber( 5 ),
                'general' => [
                    'minimal_credit_payment' => $minimalCreditPayment,
                    'payment_term_days' => $termDays,
                ],
            ] );

        $response->assertJson( [ 'status' => 'success' ] );

        $group = CustomerGroup::findOrFail( $response->json()[ 'data' ][ 'entry' ][ 'id' ] );

        $this->assertEquals(
            $termDays,
            $group->payment_term_days,
            'The credit term was not persisted by the customer group form.'
        );

        $firstName = $this->faker->firstName;
        $email = $this->faker->unique()->email;

        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', 'api/crud/ns.customers', [
                'first_name' => $firstName,
                'general' => [
                    'group_id' => $group->id,
                    'last_name' => $this->faker->lastName,
                    'email' => $email,
                ],
            ] );

        $response->assertJson( [ 'status' => 'success' ] );

        return Customer::with( 'group' )->findOrFail( $response->json()[ 'data' ][ 'entry' ][ 'id' ] );
    }

    /**
     * Picks a single product with a comfortable stock so the assertions on the remaining
     * quantity are not disturbed by other tests running against the same fixture.
     *
     * @return array{unitQuantity: ProductUnitQuantity, quantity: float, payload: array<string,mixed>}
     */
    private function pickSellableProduct( float $quantity = 2 ): array
    {
        $product = Product::notGrouped()
            ->whereRelation( 'unit_quantities', 'quantity', '>', 100 )
            ->with( 'unit_quantities', fn( $query ) => $query->where( 'quantity', '>', 100 ) )
            ->firstOrFail();

        $unitQuantity = $product->unit_quantities->first();

        return [
            'unitQuantity' => $unitQuantity,
            'quantity' => $quantity,
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
     * The headline case: goods leave, invoice is dated, nothing is tendered.
     */
    public function test_credit_order_deducts_stock_and_stamps_the_due_date(): void
    {
        $this->attemptAuthenticate();

        $customer = $this->createCreditCustomer( termDays: 30 );
        $selection = $this->pickSellableProduct( quantity: 3 );
        $quantityBefore = ProductUnitQuantity::find( $selection[ 'unitQuantity' ]->id )->quantity;

        $response = $this->postOrder( $customer, $selection[ 'payload' ] );
        $response->assertJson( [ 'status' => 'success' ] );

        $order = Order::findOrFail( $response->json()[ 'data' ][ 'order' ][ 'id' ] );

        $this->assertEquals(
            Order::PAYMENT_UNPAID,
            $order->payment_status,
            'A credit order with no payment should stay unpaid.'
        );

        /**
         * The due date. Compared on the day rather than the second, since the order is
         * stamped with the shop timezone a moment after the test read the clock.
         */
        $this->assertNotNull( $order->final_payment_date, 'The credit order received no due date.' );

        /**
         * Read from the raw column rather than the cast attribute: the cast renders the
         * date with the shop's display format, which another test is free to change.
         */
        $dueDate = ns()->date->parse( $order->getRawOriginal( 'final_payment_date' ) );

        $this->assertEquals(
            ns()->date->getNow()->addDays( 30 )->format( 'Y-m-d' ),
            $dueDate->format( 'Y-m-d' ),
            'The due date is not the sale date plus the credit term of the customer group.'
        );

        /**
         * The time of day has to survive too. The column used to be written with
         * 'Y-m-d h:m:s', which stored the month in place of the minutes and lost the
         * afternoon, so an order placed at 14:35 became 02:09.
         */
        $this->assertEquals(
            ns()->date->getNow()->addDays( 30 )->format( 'H' ),
            $dueDate->format( 'H' ),
            'The due date lost its time of day.'
        );

        /**
         * One instalment, for the whole invoice, unpaid. This is what lets the cashier
         * settle the bill later from the order screen.
         */
        $this->assertEquals( 1, $order->total_instalments );

        $instalments = OrderInstalment::where( 'order_id', $order->id )->get();

        $this->assertCount( 1, $instalments, 'The credit order should carry exactly one instalment.' );
        $this->assertFalse( (bool) $instalments->first()->paid );
        $this->assertEquals(
            ns()->currency->define( $order->total )->toFloat(),
            ns()->currency->define( $instalments->first()->amount )->toFloat(),
            'The instalment should cover the whole outstanding invoice.'
        );

        /**
         * The point of the whole feature: the shelf is lighter.
         */
        $quantityAfter = ProductUnitQuantity::find( $selection[ 'unitQuantity' ]->id )->quantity;

        $this->assertEquals(
            ns()->currency->define( $quantityBefore )->subtractBy( $selection[ 'quantity' ] )->toFloat(),
            ns()->currency->define( $quantityAfter )->toFloat(),
            'The stock was not deducted for an unpaid credit sale.'
        );

        $this->assertEquals(
            1,
            ProductHistory::where( 'order_id', $order->id )
                ->where( 'operation_type', ProductHistory::ACTION_SOLD )
                ->count(),
            'The credit sale left no stock history.'
        );
    }

    /**
     * Cost of goods sold belongs to the moment the goods leave, not the moment the money
     * arrives. A credit sale that reported its revenue with no cost against it made every
     * margin report flatter than the truth.
     */
    public function test_credit_sale_records_its_cost_of_goods(): void
    {
        $this->attemptAuthenticate();

        $rule = TransactionActionRule::where( 'on', TransactionActionRule::RULE_ORDER_COGS )->first();

        $this->assertNotNull( $rule, 'The COGS accounting rule is not configured on this installation.' );

        $customer = $this->createCreditCustomer( termDays: 30 );
        $selection = $this->pickSellableProduct( quantity: 2 );

        $response = $this->postOrder( $customer, $selection[ 'payload' ] );
        $response->assertJson( [ 'status' => 'success' ] );

        $order = Order::findOrFail( $response->json()[ 'data' ][ 'order' ][ 'id' ] );

        $this->assertEquals( Order::PAYMENT_UNPAID, $order->payment_status );

        $cogsEntries = TransactionHistory::where( 'order_id', $order->id )
            ->where( 'rule_id', $rule->id )
            ->get();

        $this->assertCount(
            1,
            $cogsEntries,
            'An unpaid credit sale recorded no cost of goods sold.'
        );

        $this->assertEquals(
            ns()->currency->define( $order->total_cogs )->toFloat(),
            ns()->currency->define( $cogsEntries->first()->value )->toFloat(),
            'The recorded cost does not match the cost of the goods on the order.'
        );

        /**
         * Settling the invoice must not book the cost a second time.
         */
        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', 'api/orders/' . $order->id . '/payments', [
                'identifier' => 'cash-payment',
                'value' => $order->total,
            ] );

        $response->assertJson( [ 'status' => 'success' ] );

        $order->refresh();

        $this->assertEquals( Order::PAYMENT_PAID, $order->payment_status );

        $this->assertEquals(
            1,
            TransactionHistory::where( 'order_id', $order->id )
                ->where( 'rule_id', $rule->id )
                ->count(),
            'Paying the invoice booked the cost of goods a second time.'
        );
    }

    /**
     * The shop has to be able to see what it is owed and when. The orders table is where
     * that is collected, so the due date has to reach it rather than staying in a column
     * nobody renders.
     */
    public function test_the_orders_table_shows_the_due_date(): void
    {
        $this->attemptAuthenticate();

        $customer = $this->createCreditCustomer( termDays: 30 );
        $selection = $this->pickSellableProduct( quantity: 1 );

        $response = $this->postOrder( $customer, $selection[ 'payload' ] );
        $response->assertJson( [ 'status' => 'success' ] );

        $order = Order::findOrFail( $response->json()[ 'data' ][ 'order' ][ 'id' ] );

        /**
         * The declared columns, which is what the table actually renders. A row carries
         * every attribute of the model whether or not a column was declared for it, so
         * asserting on the row alone would pass with no column at all.
         */
        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'GET', '/api/crud/ns.orders/columns' );

        $response->assertOk();

        $this->assertArrayHasKey(
            'final_payment_date',
            $response->json(),
            'The orders table declares no due date column.'
        );

        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'GET', '/api/crud/ns.orders?perPage=200&direction=desc&active=id' );

        $response->assertOk();

        $entry = collect( $response->json()[ 'data' ] )
            ->first( fn( $row ) => (int) $row[ 'id' ] === $order->id );

        $this->assertNotNull( $entry, 'The credit order is missing from the orders table.' );

        $this->assertNotEquals(
            __( 'N/A' ),
            $entry[ 'final_payment_date' ],
            'The orders table shows no due date for a credit order.'
        );

        $this->assertStringContainsString(
            ns()->date->parse( $order->getRawOriginal( 'final_payment_date' ) )->format( 'Y' ),
            (string) $entry[ 'final_payment_date' ],
            'The due date shown in the orders table does not match the order.'
        );
    }

    /**
     * A cash customer saved as unpaid is a layaway: the shop is holding the goods until
     * they are paid for, so the stock must not move. This is the behaviour that existed
     * before credit terms and it has to be left alone.
     */
    public function test_unpaid_order_without_credit_term_does_not_move_stock(): void
    {
        $this->attemptAuthenticate();

        $customer = $this->createCreditCustomer( termDays: 0 );
        $selection = $this->pickSellableProduct( quantity: 2 );
        $quantityBefore = ProductUnitQuantity::find( $selection[ 'unitQuantity' ]->id )->quantity;

        $response = $this->postOrder( $customer, $selection[ 'payload' ] );
        $response->assertJson( [ 'status' => 'success' ] );

        $order = Order::findOrFail( $response->json()[ 'data' ][ 'order' ][ 'id' ] );

        $this->assertEquals( Order::PAYMENT_UNPAID, $order->payment_status );
        $this->assertNull( $order->final_payment_date, 'A cash customer should receive no credit due date.' );
        $this->assertEquals( 0, OrderInstalment::where( 'order_id', $order->id )->count() );

        $quantityAfter = ProductUnitQuantity::find( $selection[ 'unitQuantity' ]->id )->quantity;

        $this->assertEquals(
            ns()->currency->define( $quantityBefore )->toFloat(),
            ns()->currency->define( $quantityAfter )->toFloat(),
            'A layaway should keep holding its stock.'
        );
    }

    /**
     * Settling the invoice must not deduct the goods a second time. The stock listener
     * runs again on the transition to paid, so this depends on
     * saveOrderProductHistory() skipping what it already recorded.
     */
    public function test_settling_a_credit_order_does_not_deduct_stock_twice(): void
    {
        $this->attemptAuthenticate();

        $customer = $this->createCreditCustomer( termDays: 15 );
        $selection = $this->pickSellableProduct( quantity: 2 );
        $quantityBefore = ProductUnitQuantity::find( $selection[ 'unitQuantity' ]->id )->quantity;

        $response = $this->postOrder( $customer, $selection[ 'payload' ] );
        $response->assertJson( [ 'status' => 'success' ] );

        $order = Order::findOrFail( $response->json()[ 'data' ][ 'order' ][ 'id' ] );

        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', 'api/orders/' . $order->id . '/payments', [
                'identifier' => 'cash-payment',
                'value' => $order->total,
            ] );

        $response->assertJson( [ 'status' => 'success' ] );

        $order->refresh();

        $this->assertEquals( Order::PAYMENT_PAID, $order->payment_status );

        $quantityAfter = ProductUnitQuantity::find( $selection[ 'unitQuantity' ]->id )->quantity;

        $this->assertEquals(
            ns()->currency->define( $quantityBefore )->subtractBy( $selection[ 'quantity' ] )->toFloat(),
            ns()->currency->define( $quantityAfter )->toFloat(),
            'Paying a credit order deducted the stock a second time.'
        );
    }

    /**
     * Once the term lapses the order has to become due without anyone touching it. That
     * sweep is TrackLaidAwayOrdersJob, scheduled daily, which calls this service method.
     */
    public function test_credit_order_turns_due_once_the_term_lapses(): void
    {
        $this->attemptAuthenticate();

        $customer = $this->createCreditCustomer( termDays: 30 );
        $selection = $this->pickSellableProduct( quantity: 1 );

        $response = $this->postOrder( $customer, $selection[ 'payload' ] );
        $response->assertJson( [ 'status' => 'success' ] );

        $order = Order::findOrFail( $response->json()[ 'data' ][ 'order' ][ 'id' ] );

        /**
         * Travelling the order back rather than the clock, so the rest of the fixture
         * keeps its own dates.
         */
        $order->final_payment_date = ns()->date->getNow()->subDay()->toDateTimeString();
        $order->save();

        $this->assertTrue(
            Order::paymentExpired()->where( 'id', $order->id )->exists(),
            'An overdue credit order was not picked up by the expired payment scope.'
        );

        app()->make( OrdersService::class )->notifyExpiredLaidAway();

        $order->refresh();

        $this->assertEquals(
            Order::PAYMENT_DUE,
            $order->payment_status,
            'An overdue credit order should turn due.'
        );
    }

    /**
     * A group may ask for part of the invoice up front before releasing the goods. Until
     * now that setting only applied to layaways, so it could be configured on a credit
     * group and quietly do nothing.
     */
    public function test_credit_group_can_require_a_minimum_payment(): void
    {
        $this->attemptAuthenticate();

        $customer = $this->createCreditCustomer( termDays: 30, minimalCreditPayment: 50 );
        $selection = $this->pickSellableProduct( quantity: 2 );
        $quantityBefore = ProductUnitQuantity::find( $selection[ 'unitQuantity' ]->id )->quantity;

        $response = $this->postOrder( $customer, $selection[ 'payload' ] );

        $response->assertJson( [ 'status' => 'error' ] );

        $quantityAfter = ProductUnitQuantity::find( $selection[ 'unitQuantity' ]->id )->quantity;

        $this->assertEquals(
            ns()->currency->define( $quantityBefore )->toFloat(),
            ns()->currency->define( $quantityAfter )->toFloat(),
            'A rejected credit order must not have moved any stock.'
        );
    }

    /**
     * The credit limit already granted to an account still caps what can be taken on
     * credit. A term is permission to pay late, not permission to owe without bound.
     */
    public function test_credit_limit_still_blocks_an_order_beyond_the_allowance(): void
    {
        $this->attemptAuthenticate();

        $customer = $this->createCreditCustomer( termDays: 30 );
        $selection = $this->pickSellableProduct( quantity: 2 );

        $customer->credit_limit_amount = 1;
        $customer->save();

        $response = $this->postOrder( $customer, $selection[ 'payload' ] );

        $response->assertJson( [ 'status' => 'error' ] );

        $this->assertEquals(
            0,
            Order::where( 'customer_id', $customer->id )->count(),
            'An order exceeding the credit limit should not have been recorded.'
        );
    }

    /**
     * Tendering a token amount used to slip past the credit limit entirely: the order was
     * partially paid, the check only looked at orders with no payment at all, and the rest
     * of the invoice was lent without any ceiling.
     */
    public function test_a_token_payment_does_not_bypass_the_credit_limit(): void
    {
        $this->attemptAuthenticate();

        $customer = $this->createCreditCustomer( termDays: 30 );
        $selection = $this->pickSellableProduct( quantity: 3 );

        $customer->credit_limit_amount = 1;
        $customer->save();

        $response = $this->postOrder( $customer, $selection[ 'payload' ], [
            'tendered' => 1,
            'payments' => [
                [
                    'identifier' => 'cash-payment',
                    'value' => 1,
                ],
            ],
        ] );

        $response->assertJson( [ 'status' => 'error' ] );

        $this->assertEquals(
            0,
            Order::where( 'customer_id', $customer->id )->count(),
            'A partially paid order beyond the credit limit should not have been recorded.'
        );
    }

    /**
     * The limit applies to what is actually lent, so paying most of the invoice up front
     * has to leave the customer inside his allowance.
     */
    public function test_the_limit_applies_to_the_unpaid_part_of_the_order(): void
    {
        $this->attemptAuthenticate();

        $customer = $this->createCreditCustomer( termDays: 30 );
        $selection = $this->pickSellableProduct( quantity: 2 );

        $total = ns()->currency->define( $selection[ 'payload' ][ 'unit_price' ] )
            ->multipliedBy( $selection[ 'payload' ][ 'quantity' ] )
            ->toFloat();

        /**
         * An allowance that covers a tenth of the order and nothing more. Paying all but
         * that tenth has to be accepted, which the old check could not express since it
         * compared the allowance against the whole total.
         */
        $onCredit = ns()->currency->define( $total )->dividedBy( 10 )->toFloat();
        $upFront = ns()->currency->define( $total )->subtractBy( $onCredit )->toFloat();

        $customer->credit_limit_amount = $onCredit;
        $customer->save();

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
    }
}
