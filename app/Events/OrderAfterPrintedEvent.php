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

class OrderAfterPrintedEvent implements ResilientBroadcast
{
    use BroadcastsOrderSummary, Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The documents the printing settings offer — see app/Settings/pos/printing.php.
     *
     * @var array<int, string>
     */
    private const PRINTABLE_DOCUMENTS = [ 'receipt', 'invoice' ];

    public function __construct( public Order $order, public $doc )
    {
        // ...
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * Moved off "ns.private-channel", which had no authorization callback registered and
     * so could never be subscribed to.
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
        return 'order.printed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'order' => $this->orderSummary( $this->order ),

            /**
             * Which document was printed, restricted to the two the shop can actually
             * choose between in the printing settings.
             *
             * An allowlist rather than a pass-through: $doc arrives from the {doc?} URL
             * segment of the print route with no constraint on it, so anything a caller puts
             * there would otherwise be relayed verbatim to every subscribed dashboard.
             */
            'doc' => in_array( $this->doc, self::PRINTABLE_DOCUMENTS, true ) ? $this->doc : null,
        ];
    }
}
