<?php

namespace App\Events;

use App\Broadcasting\ResilientBroadcast;
use App\Models\ProductUnitQuantity;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Announces that the stock of one product unit changed.
 *
 * A shop runs several tills against one catalogue. Each till loads products once
 * per category and keeps them in memory, so without this event a sale on till A is
 * invisible to till B until the cashier navigates away and back. The cashier then
 * adds an item the shop no longer has, and only finds out when the sale is
 * rejected at payment — in front of the customer.
 *
 * The payload is deliberately the three values a till needs to patch its cached
 * product list, not the whole model. A product row carries cost prices and
 * supplier references that have no business being pushed to a till screen.
 */
class ProductStockUpdatedEvent implements ResilientBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Deliberately NOT marked $afterCommit.
     *
     * Deferring the broadcast to commit time is the right timing, but Laravel
     * implements it by postponing the queue push, and with the sync queue driver the
     * push performs the broadcast inline — during DB::commit(). A broadcast failure
     * would then surface out of the commit itself, after the order has already been
     * written, and unwinding that cleanly is not something the caller can do.
     *
     * ProductService::announceStockChange() owns the timing instead: it registers a
     * DB::afterCommit callback and swallows broadcast errors there. ShouldRescue (via
     * ResilientBroadcast) is the second layer — see App\Broadcasting\ResilientBroadcast.
     */
    public function __construct(
        public int $product_id,
        public int $unit_quantity_id,
        public int $unit_id,
        public float $quantity,
    ) {
        // ...
    }

    /**
     * Builds the event from a unit quantity row.
     */
    public static function fromUnitQuantity( ProductUnitQuantity $unitQuantity ): self
    {
        return new self(
            product_id: (int) $unitQuantity->product_id,
            unit_quantity_id: (int) $unitQuantity->id,
            unit_id: (int) $unitQuantity->unit_id,
            quantity: (float) $unitQuantity->quantity,
        );
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * Public on purpose. The payload is a product id and a quantity, which the POS grid
     * already shows to anyone who can open it, and one shared channel avoids fanning the
     * same message out once per authenticated cashier. Nothing here identifies a customer
     * or reveals a cost price.
     *
     * @return Channel|array
     */
    public function broadcastOn()
    {
        return [
            new Channel( 'stock' ),
        ];
    }

    public function broadcastAs(): string
    {
        return 'stock.updated';
    }

    /**
     * @return array{ product_id: int, unit_quantity_id: int, unit_id: int, quantity: float }
     */
    public function broadcastWith(): array
    {
        return [
            'product_id' => $this->product_id,
            'unit_quantity_id' => $this->unit_quantity_id,
            'unit_id' => $this->unit_id,
            'quantity' => $this->quantity,
        ];
    }
}
