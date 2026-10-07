<?php

namespace Tests\Feature;

use App\Exceptions\NotAllowedException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentType;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\User;
use App\Services\OrdersService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;
use Tests\Traits\WithCustomerTest;

/**
 * Covers the payment channels a Thai retail shop takes, and the report that reconciles
 * the ones where the money arrives after the sale.
 *
 * The properties under test are the ones a shop owner would notice if they broke: the four
 * channels are offered at the till and the retired one is not, a payment on a retired
 * channel is refused rather than silently accepted, the reference the cashier typed
 * survives to the database, and a channel that has been switched off keeps its history in
 * a report for a period that already closed.
 */
class ThaiPaymentChannelsTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication, WithCustomerTest;

    private OrdersService $orders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orders = app()->make( OrdersService::class );

        ns()->option->set( 'ns_currency_precision', 2 );

        /**
         * OrdersService::create() calls ns()->restrict( 'nexopos.make-payment.orders' ) as
         * soon as a payment is present, so there has to be a logged in user with that
         * permission for any of this to run.
         */
        $this->attemptAuthenticate();
    }

    public function test_the_till_offers_the_four_thai_channels(): void
    {
        $active = PaymentType::active()->pluck( 'identifier' );

        foreach ( [
            OrderPayment::PAYMENT_CASH,
            OrderPayment::PAYMENT_PROMPTPAY,
            OrderPayment::PAYMENT_BANK,
            OrderPayment::PAYMENT_WELFARE_CARD,
        ] as $identifier ) {
            $this->assertContains( $identifier, $active, $identifier . ' must be available at the till.' );
        }
    }

    public function test_the_customer_account_channel_is_retired_but_kept(): void
    {
        $account = PaymentType::identifier( OrderPayment::PAYMENT_ACCOUNT )->first();

        /**
         * Kept on purpose. refundOrder() settles a refund to store credit through this
         * identifier, and RegisterHistory resolves a payment type by identifier, so
         * deleting the row would break both the refund path and drawer history for every
         * account payment already taken.
         */
        $this->assertInstanceOf( PaymentType::class, $account, 'The channel is retired by deactivating it, never by deleting it.' );
        $this->assertFalse( (bool) $account->active );
        $this->assertNotContains( OrderPayment::PAYMENT_ACCOUNT, PaymentType::active()->pluck( 'identifier' ) );
    }

    public function test_the_channels_have_a_deterministic_order(): void
    {
        $thaiChannels = [
            OrderPayment::PAYMENT_CASH,
            OrderPayment::PAYMENT_PROMPTPAY,
            OrderPayment::PAYMENT_BANK,
            OrderPayment::PAYMENT_WELFARE_CARD,
        ];

        $priorities = PaymentType::whereIn( 'identifier', $thaiChannels )
            ->orderBy( 'priority', 'asc' )
            ->pluck( 'priority', 'identifier' );

        /**
         * The stock installer left every channel on priority 0, which left the payment
         * popup ordering to whatever the database happened to return first. Scoped to the
         * four channels installed here, because other tests add their own.
         */
        $this->assertSame( OrderPayment::PAYMENT_CASH, $priorities->keys()->first() );
        $this->assertCount(
            count( $thaiChannels ),
            $priorities->values()->unique(),
            'Two channels sharing a priority makes the till ordering arbitrary.'
        );
    }

    public function test_a_payment_on_a_retired_channel_is_refused(): void
    {
        $this->expectException( NotAllowedException::class );

        /**
         * The whitelist in __checkOrderPayments is built from the active channels, so
         * deactivating one has to be enough to stop it being used on a new sale.
         */
        $this->orders->create( $this->givenOrderPayload( OrderPayment::PAYMENT_ACCOUNT ) );
    }

    public function test_a_promptpay_payment_keeps_the_reference_the_cashier_typed(): void
    {
        $result = $this->orders->create(
            $this->givenOrderPayload( OrderPayment::PAYMENT_PROMPTPAY, reference: 'SLIP-8891' )
        );

        $payment = OrderPayment::where( 'order_id', $result[ 'data' ][ 'order' ]->id )->firstOrFail();

        $this->assertSame( OrderPayment::PAYMENT_PROMPTPAY, $payment->identifier );
        $this->assertSame( 'SLIP-8891', $payment->reference );
    }

    public function test_a_cash_payment_carries_no_reference(): void
    {
        $result = $this->orders->create( $this->givenOrderPayload( OrderPayment::PAYMENT_CASH ) );

        $payment = OrderPayment::where( 'order_id', $result[ 'data' ][ 'order' ]->id )->firstOrFail();

        /**
         * Nothing to reconcile: the money is in the drawer. An empty string would read as
         * "a reference was recorded" to the reconciliation report.
         */
        $this->assertNull( $payment->reference );
    }

    public function test_a_blank_reference_is_stored_as_nothing(): void
    {
        $result = $this->orders->create(
            $this->givenOrderPayload( OrderPayment::PAYMENT_WELFARE_CARD, reference: '   ' )
        );

        $payment = OrderPayment::where( 'order_id', $result[ 'data' ][ 'order' ]->id )->firstOrFail();

        $this->assertNull( $payment->reference );
    }

    public function test_the_report_splits_takings_by_channel(): void
    {
        $start = ns()->date->getNow()->startOfDay()->toDateTimeString();
        $end = ns()->date->getNow()->endOfDay()->toDateTimeString();

        $before = $this->orders->getPaymentTypesReport( $start, $end );
        $beforeWelfare = $this->channelTotal( $before, OrderPayment::PAYMENT_WELFARE_CARD );
        $beforeCash = $this->channelTotal( $before, OrderPayment::PAYMENT_CASH );

        $this->orders->create( $this->givenOrderPayload( OrderPayment::PAYMENT_WELFARE_CARD, reference: 'EDC-0001', total: 120.0 ) );

        $after = $this->orders->getPaymentTypesReport( $start, $end );

        $this->assertEqualsWithDelta( $beforeWelfare + 120.0, $this->channelTotal( $after, OrderPayment::PAYMENT_WELFARE_CARD ), 0.02 );
        $this->assertEqualsWithDelta( $beforeCash, $this->channelTotal( $after, OrderPayment::PAYMENT_CASH ), 0.02 );
        $this->assertEqualsWithDelta( $before[ 'total' ] + 120.0, $after[ 'total' ], 0.02 );
    }

    public function test_the_report_lists_the_references_for_channels_that_settle_later(): void
    {
        $start = ns()->date->getNow()->startOfDay()->toDateTimeString();
        $end = ns()->date->getNow()->endOfDay()->toDateTimeString();

        $this->orders->create( $this->givenOrderPayload( OrderPayment::PAYMENT_WELFARE_CARD, reference: 'EDC-4242', total: 75.0 ) );

        $report = $this->orders->getPaymentTypesReport( $start, $end );

        $welfare = collect( $report[ 'reconciliation' ] )
            ->firstWhere( 'identifier', OrderPayment::PAYMENT_WELFARE_CARD );

        $this->assertNotNull( $welfare, 'The welfare card channel has to appear in the reconciliation block.' );
        $this->assertContains( 'EDC-4242', collect( $welfare[ 'references' ] )->pluck( 'reference' ) );

        /**
         * Cash settles at the till, so there is nothing to match it against and it has no
         * business in a reconciliation block.
         */
        $this->assertNull(
            collect( $report[ 'reconciliation' ] )->firstWhere( 'identifier', OrderPayment::PAYMENT_CASH )
        );
    }

    public function test_the_report_counts_payments_missing_a_reference(): void
    {
        $start = ns()->date->getNow()->startOfDay()->toDateTimeString();
        $end = ns()->date->getNow()->endOfDay()->toDateTimeString();

        $before = collect( $this->orders->getPaymentTypesReport( $start, $end )[ 'reconciliation' ] )
            ->firstWhere( 'identifier', OrderPayment::PAYMENT_WELFARE_CARD )[ 'missing_reference' ] ?? 0;

        $this->orders->create( $this->givenOrderPayload( OrderPayment::PAYMENT_WELFARE_CARD, total: 60.0 ) );

        $after = collect( $this->orders->getPaymentTypesReport( $start, $end )[ 'reconciliation' ] )
            ->firstWhere( 'identifier', OrderPayment::PAYMENT_WELFARE_CARD );

        /**
         * A welfare card charge with no approval code cannot be matched against the
         * government's transfer. The shop needs to be told before the statement arrives.
         */
        $this->assertSame( $before + 1, $after[ 'missing_reference' ] );
    }

    public function test_a_deactivated_channel_keeps_its_history_in_the_report(): void
    {
        $start = ns()->date->getNow()->startOfDay()->toDateTimeString();
        $end = ns()->date->getNow()->endOfDay()->toDateTimeString();

        $this->orders->create( $this->givenOrderPayload( OrderPayment::PAYMENT_PROMPTPAY, reference: 'SLIP-7', total: 90.0 ) );

        $promptpay = PaymentType::identifier( OrderPayment::PAYMENT_PROMPTPAY )->firstOrFail();
        $promptpay->active = false;
        $promptpay->save();

        $report = $this->orders->getPaymentTypesReport( $start, $end );

        /**
         * Reading the report off the active channels only would make a closed period's
         * figures change the moment a shop retires a channel — the report would be
         * rewriting its own history.
         */
        $row = collect( $report[ 'summary' ] )->firstWhere( 'identifier', OrderPayment::PAYMENT_PROMPTPAY );

        $this->assertNotNull( $row );
        $this->assertFalse( $row[ 'active' ] );
        $this->assertGreaterThanOrEqual( 90.0, $row[ 'total' ] );
    }

    /**
     * @param array{ summary: array } $report
     */
    private function channelTotal( array $report, string $identifier ): float
    {
        $row = collect( $report[ 'summary' ] )->firstWhere( 'identifier', $identifier );

        return (float) ( $row[ 'total' ] ?? 0 );
    }

    /**
     * A single line order paid in full through one channel.
     *
     * Built as a payload for OrdersService::create() rather than inserted directly, because
     * what is under test includes the validation and the persistence that only run on that
     * path.
     *
     * @return array<string, mixed>
     */
    private function givenOrderPayload( string $identifier, ?string $reference = null, float $total = 100.0 ): array
    {
        $unitQuantity = ProductUnitQuantity::whereHas( 'product', function ( $query ) {
            $query->where( 'type', '!=', Product::TYPE_GROUPED )
                ->where( 'stock_management', Product::STOCK_MANAGEMENT_DISABLED );
        } )->with( 'product' )->first();

        /**
         * Stock is not what is being tested here, so a product that does not track it is
         * preferred — it keeps the payload from failing on an unrelated stock shortage.
         * Falling back keeps the test runnable against a fixture that has none.
         */
        $unitQuantity ??= ProductUnitQuantity::whereHas(
            'product',
            fn( $query ) => $query->where( 'type', '!=', Product::TYPE_GROUPED )
        )->with( 'product' )->firstOrFail();

        $customer = Customer::first() ?: $this->attemptCreateCustomer();

        $payment = [
            'identifier' => $identifier,
            'value' => $total,
        ];

        if ( $reference !== null ) {
            $payment[ 'reference' ] = $reference;
        }

        return [
            'customer_id' => $customer->id,
            'type' => [ 'identifier' => Order::TYPE_TAKEAWAY ],
            'discount_type' => null,
            'discount_percentage' => 0,
            'discount' => 0,
            'addresses' => [],
            'author' => User::first()->id,
            'coupons' => [],
            'subtotal' => $total,
            'shipping' => 0,
            'products' => [
                [
                    'product_id' => $unitQuantity->product_id,
                    'quantity' => 1,
                    'unit_price' => $total,
                    'unit_quantity_id' => $unitQuantity->id,
                ],
            ],
            'payments' => [ $payment ],
        ];
    }
}
