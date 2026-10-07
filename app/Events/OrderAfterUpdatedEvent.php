<?php

namespace App\Events;

use App\Broadcasting\BroadcastsOrderSummary;
use App\Broadcasting\ResilientBroadcast;
use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderAfterUpdatedEvent implements ResilientBroadcast
{
    use BroadcastsOrderSummary, Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct( public Order $order )
    {
        // ...
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * Private and permission gated. See OrderAfterCreatedEvent for why this is no longer
     * the public "default-channel".
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
        return 'order.updated';
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
