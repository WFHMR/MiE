<?php

namespace App\Listeners;

use App\Events\OrderAfterPaymentStatusChangedEvent;
use App\Models\Order;
use App\Services\BranchLoyaltyService;
use App\Services\CashRegistersService;
use App\Services\OrdersService;
use App\Services\TransactionService;

class OrderAfterPaymentStatusChangedEventListener
{
    /**
     * Create the event listener.
     */
    public function __construct(
        public TransactionService $transactionService,
        public OrdersService $ordersService,
        public CashRegistersService $cashRegistersService
    ) {
        //
    }

    /**
     * Handle the event.
     */
    public function handle( OrderAfterPaymentStatusChangedEvent $event ): void
    {
        app( BranchLoyaltyService::class )->settle( $event->order );
        if ( $event->new === Order::PAYMENT_VOID ) {
            app( BranchLoyaltyService::class )->reverse( $event->order, true );
        }
        /**
         * Step: Order Directly Paid
         * when the order is directly paid or directly unpaid we handle the transactions directly
         */
        if (
            (
                $event->previous === null &&
                $event->new === Order::PAYMENT_PAID
            ) ||
            (
                $event->previous === null &&
                $event->new === Order::PAYMENT_UNPAID
            )
        ) {
            $this->transactionService->handleSaleTransaction( $event->order );
        }

        /**
         * We want to decide from when the inventory
         * is moved out to the customer destination. And order that is partially paid start holding the
         * inventory for the customer. In case there is no sale in due time, the inventory might be returned.
         *
         * A credit sale is the exception. The customer of a group that grants a payment
         * term walks out with the goods against an invoice, so the stock has physically
         * left even though nothing was tendered. Deducting only on payment made the shop
         * count goods it no longer had, and the next sale of the same item oversold.
         *
         * Cost of goods sold is recorded at the same moment, because that is when the
         * shop actually gave up the goods. It used to be written only for an order that
         * was paid outright, so every layaway and every credit sale reported its revenue
         * with no cost against it.
         *
         * Both calls skip what they have already written, so an order passing through
         * here again on its way to paid neither deducts nor costs twice.
         */
        if ( $this->releasesInventory( $event ) ) {
            $this->ordersService->saveOrderProductHistory( $event->order );
            $this->transactionService->handleCogsFromSale( $event->order );
        }

        /**
         * Step: Order From Unpaid to Paid
         * when the order payment status is changed we handle the transactions
         */
        if (
            in_array( $event->previous, [
                Order::PAYMENT_UNPAID,
                Order::PAYMENT_HOLD,
                Order::PAYMENT_PARTIALLY,
            ] ) &&
            $event->new === Order::PAYMENT_PAID
        ) {
            $this->transactionService->handleUnpaidToPaidSaleTransaction( $event->order );
        }

        /**
         * Step: Order from Paid to Void
         */
        if (
            $event->previous === Order::PAYMENT_PAID &&
            $event->new === Order::PAYMENT_VOID
        ) {
            $this->transactionService->handlePaidToVoidSaleTransaction( $event->order );
            $this->ordersService->returnVoidProducts( $event->order );
        }

        /**
         * Step: Order from Unpaid to Void
         */
        if (
            in_array( $event->previous, [
                Order::PAYMENT_UNPAID,
                Order::PAYMENT_PARTIALLY,
            ] ) &&
            $event->new === Order::PAYMENT_VOID
        ) {
            $this->transactionService->handleUnpaidToVoidSaleTransaction( $event->order );
            $this->ordersService->returnVoidProducts( $event->order );
        }
    }

    /**
     * Whether this change of payment status is the point at which the goods leave the
     * shop and become the customer's.
     *
     * Everything that follows from handing the goods over hangs off this single answer:
     * the stock movement and the cost recorded against the sale. Keeping it in one place
     * stops the two from drifting apart, which is how a sale ended up with revenue and no
     * cost against it.
     */
    private function releasesInventory( OrderAfterPaymentStatusChangedEvent $event ): bool
    {
        if ( ! in_array( $event->previous, [ null, Order::PAYMENT_HOLD, Order::PAYMENT_UNPAID, Order::PAYMENT_PARTIALLY ] ) ) {
            return false;
        }

        if ( in_array( $event->new, [ Order::PAYMENT_PAID, Order::PAYMENT_PARTIALLY ] ) ) {
            return true;
        }

        return $event->new === Order::PAYMENT_UNPAID && $event->order->isCreditSale();
    }
}
