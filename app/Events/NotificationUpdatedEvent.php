<?php

namespace App\Events;

use App\Broadcasting\ResilientBroadcast;
use App\Models\Notification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Queue\SerializesModels;

class NotificationUpdatedEvent implements ResilientBroadcast
{
    use InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct( public Notification $notification )
    {
        //
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel( 'App.User.' . $this->notification->user_id ),
        ];
    }
}
