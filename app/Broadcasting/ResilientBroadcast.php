<?php

namespace App\Broadcasting;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;

/**
 * A broadcast that must never be able to fail the operation that produced it.
 *
 * Every queued job in this application runs on the sync driver, the cash drawer tracking
 * among them, so the queue cannot be moved to a worker without silently making the drawer
 * asynchronous. Sync queue means broadcasting happens inline: a ShouldBroadcast event
 * dispatched inside a sale performs an HTTP call to the websocket server while the sale is
 * still in flight.
 *
 * Without ShouldRescue that is a hard dependency. Reverb stops, the next order throws a
 * BroadcastException out of the middle of the sale, and the till reports a failure for a
 * sale that may already have taken the customer's money. With it, BroadcastManager wraps
 * the push in rescue(): the failure is reported to the exception handler and the sale
 * carries on.
 *
 * The trade is deliberate and one-directional. A till that misses a live update is a
 * degradation the cashier can work around with a refresh. A sale that reports failure
 * after taking money is not something a shop can work around at all.
 *
 * Every ShouldBroadcast event in this application implements this instead of
 * ShouldBroadcast directly. If a future event genuinely must fail loudly when it cannot be
 * delivered, it should implement ShouldBroadcast on its own and say why.
 */
interface ResilientBroadcast extends ShouldBroadcast, ShouldRescue
{
    //
}
