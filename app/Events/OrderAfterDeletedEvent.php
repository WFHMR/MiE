<?php

namespace App\Events;

use App\Broadcasting\BroadcastsOrderSummary;
use App\Broadcasting\ResilientBroadcast;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderAfterDeletedEvent implements ResilientBroadcast
{
    use BroadcastsOrderSummary, Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The delete flow hands over a plain object rather than an Order model, which is why
     * this stays untyped and why the payload is read defensively.
     */
    public function __construct( public $order )
    {
        // ...
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * Moved off "ns.private-channel", which had no authorization callback registered — a
     * private channel with no callback is a channel nothing can ever subscribe to, so this
     * event has never been delivered.
     *
     * @return Channel|array
     */
    public function broadcastOn()
    {
        return [
            new PrivateChannel( 'orders' ),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.deleted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'order' => $this->orderSummary( $this->order ),
        ];
    }
}
