<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\TransactionActionRule;
use App\Services\TestService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tests\Traits\WithAccountingTest;
use Tests\Traits\WithAuthentication;
use Tests\Traits\WithOrderTest;

/**
 * The accounting side of selling: unpaid, paid, voided either way, unpaid then paid, deleted, and cost
 * of goods sold.
 *
 * Same cause as AccountingProcurementTest — it extended a TestCase belonging to a module that is not
 * installed, so the file never loaded and never ran. The money side of a sale reaching the ledger was
 * therefore covered by nothing.
 */
class AccountingOrderTest extends TestCase
{
    use DatabaseTransactions, WithAccountingTest, WithAuthentication, WithOrderTest;

    /**
     * Four of the tests below sell on credit, and whether a credit sale is allowed depends on the customer
     * the fixture happens to draw: OrdersService refuses one whose credit ceiling is already reached, and
     * refuses any credit sale at all when the customer's group demands a minimum payment first. The draw is
     * random and the ceiling fills up as the suite creates unpaid orders, so this file passed on its own and
     * failed inside the suite with "Undefined array key order" — the refusal read as a missing key because
     * the response body was never checked.
     *
     * Both obstacles are cleared here rather than left to chance. The trait rolls it back, so the shop's
     * own credit rules in the fixture are handed on untouched.
     */
    protected function setUp(): void
    {
        parent::setUp();

        CustomerGroup::query()->update( [ 'minimal_credit_payment' => 0 ] );
        Customer::query()->update( [ 'credit_limit_amount' => 0 ] );
    }

    public function test_unpaid_order()
    {
        // we can fairly assume accounting is setup so far
        $this->attemptAuthenticate();

        /**
         * @var TestService $testService
         */
        $testService = app()->make( TestService::class );

        $response = $this->attemptCreateOrder( $testService->prepareOrder(
            config: [
                'payments' => fn() => [],
            ]
        ) );

        $response->assertOk();
        $order = $response->json()[ 'data' ][ 'order' ];

        $this->attemptTestAccountingForOrder( Order::findOrFail( $order[ 'id' ] ) );
    }

    public function test_paid_order()
    {
        // we can fairly assume accounting is setup so far
        $this->attemptAuthenticate();

        /**
         * @var TestService $testService
         */
        $testService = app()->make( TestService::class );

        $response = $this->attemptCreateOrder( $testService->prepareOrder() );

        $response->assertOk();
        $order = $response->json()[ 'data' ][ 'order' ];

        $this->attemptTestAccountingForOrder( Order::findOrFail( $order[ 'id' ] ) );
    }

    public function test_void_paid_order()
    {
        $this->attemptAuthenticate();

        /**
         * @var TestService $testService
         */
        $testService = app()->make( TestService::class );

        $response = $this->attemptCreateOrder( $testService->prepareOrder() );

        $order = $response->json()[ 'data' ][ 'order' ];
        $order = Order::findOrFail( $order[ 'id' ] );

        $this->attemptVoidOrder( $order );
        $this->attemptTestAccountingForVoidedOrder( $order, TransactionActionRule::RULE_ORDER_PAID_VOIDED );
    }

    public function test_void_unpaid_order()
    {
        $this->attemptAuthenticate();

        /**
         * @var TestService $testService
         */
        $testService = app()->make( TestService::class );

        $response = $this->attemptCreateOrder( $testService->prepareOrder(
            config: [
                'payments' => fn() => [],
            ]
        ) );

        $order = $response->json()[ 'data' ][ 'order' ];
        $order = Order::findOrFail( $order[ 'id' ] );

        $this->attemptVoidOrder( $order );
        $this->attemptTestAccountingForVoidedOrder( $order, TransactionActionRule::RULE_ORDER_UNPAID_VOIDED );
    }

    public function test_order_from_unpaid_to_paid()
    {
        $this->attemptAuthenticate();

        /**
         * @var TestService $testService
         */
        $testService = app()->make( TestService::class );

        $response = $this->attemptCreateOrder( $testService->prepareOrder(
            config: [
                'payments' => fn() => [],
            ]
        ) );

        /**
         * Checked before the body is read. Without it a refusal — a credit limit, a customer group's
         * minimum payment — surfaced as "Undefined array key order" from the line below, which names the
         * test but not the reason.
         */
        $this->assertSame(
            200,
            $response->status(),
            'Creating the unpaid order was refused: ' . $response->getContent()
        );

        $order = $response->json()[ 'data' ][ 'order' ];
        $order = Order::findOrFail( $order[ 'id' ] );

        $this->attemptCompleteOrderPayment( $order, OrderPayment::PAYMENT_CASH );

        $this->attemptTestAccountingForUnpaidToPaidOrder( $order );
    }

    public function test_delete_order()
    {
        $this->attemptAuthenticate();

        /**
         * @var TestService $testService
         */
        $testService = app()->make( TestService::class );

        $response = $this->attemptCreateOrder( $testService->prepareOrder() );

        $order = $response->json()[ 'data' ][ 'order' ];
        $order = Order::findOrFail( $order[ 'id' ] );

        $this->attemptDeleteOrder( $order );
        $this->attemptTestAccountingForDeletedOrder( $order );
    }

    public function test_order_cogs()
    {
        $this->attemptAuthenticate();

        /**
         * @var TestService $testService
         */
        $testService = app()->make( TestService::class );

        $response = $this->attemptCreateOrder( $testService->prepareOrder() );

        $order = $response->json()[ 'data' ][ 'order' ];
        $order = Order::findOrFail( $order[ 'id' ] );

        $this->attemptTestAccountingForOrderCogs( $order );
    }
}
