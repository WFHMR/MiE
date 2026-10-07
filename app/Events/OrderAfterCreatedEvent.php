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

class OrderAfterCreatedEvent implements ResilientBroadcast
{
    use BroadcastsOrderSummary, Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct( public Order $order )
    {
        // ...
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * Private, and gated on the orders read permission in routes/channels.php. This used
     * to be the public "default-channel", which meant any browser that opened a websocket
     * received every order in the shop — invisible while the broadcast driver was "log",
     * and a data leak the moment it is not.
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
        return 'order.created';
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
