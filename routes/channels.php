<?php

use App\Broadcasting\OrdersChannel;
use App\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel( 'App.User.{id}', PrivateChannel::class );

/**
 * Kept registered because a module may still publish to it, but nothing in core does any
 * more. It is public, so anything put here is readable by any browser that opens a
 * websocket — see the orders channel below for why the order events were moved off it.
 */
Broadcast::channel( 'default-channel', Channel::class );

/**
 * Order activity for open dashboards: a sale rung up on one terminal, an order edited or
 * deleted, a receipt printed.
 *
 * Private and gated on the orders read permission. The four order events used to publish
 * the entire Order model on the public "default-channel", which was harmless only because
 * the broadcast driver was "log" and nothing left the server. With Reverb enabled that
 * would hand every sale — customer, totals, discounts — to anyone who could reach the
 * websocket port.
 */
Broadcast::channel( 'orders', OrdersChannel::class );

/**
 * Carries product quantity changes to every open till so a sale on one terminal is
 * reflected on the others without a reload. See ProductStockUpdatedEvent.
 *
 * Public rather than private: the payload is a product id and a quantity, which the
 * POS grid already exposes to anyone who can open it, and a shared channel avoids
 * fanning the same message out per authenticated cashier.
 */
Broadcast::channel( 'stock', Channel::class );
