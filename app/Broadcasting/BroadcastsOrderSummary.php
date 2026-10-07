<?php

namespace App\Broadcasting;

/**
 * Shapes the payload an order event puts on the wire.
 *
 * Without an explicit payload, Laravel serialises every public property of the event — for
 * these events that is the whole Order model. An order carries the customer id, the
 * discount, the cost of goods and whatever relations happened to be loaded at dispatch
 * time, and none of that belongs on a websocket whose only job is to tell an open dashboard
 * that the list has changed.
 *
 * What is sent is what the orders list already shows to anyone allowed to read orders, and
 * the channel is gated on that same permission.
 */
trait BroadcastsOrderSummary
{
    /**
     * @return array{ id: int|null, code: string|null, payment_status: string|null, process_status: string|null, delivery_status: string|null, total: float, created_at: string|null }
     */
    protected function orderSummary( mixed $order ): array
    {
        /**
         * The delete flow hands over a plain object rather than a model, so the properties
         * are read defensively instead of through the Order contract.
         */
        return [
            'id' => isset( $order->id ) ? (int) $order->id : null,
            'code' => $order->code ?? null,
            'payment_status' => $order->payment_status ?? null,
            'process_status' => $order->process_status ?? null,
            'delivery_status' => $order->delivery_status ?? null,
            'total' => isset( $order->total ) ? (float) $order->total : 0.0,
            'created_at' => isset( $order->created_at ) ? (string) $order->created_at : null,
        ];
    }
}
