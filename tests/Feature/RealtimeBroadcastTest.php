<?php

namespace Tests\Feature;

use App\Broadcasting\BroadcastCircuit;
use App\Broadcasting\CircuitBreakingBroadcaster;
use App\Broadcasting\OrdersChannel;
use App\Broadcasting\ResilientBroadcast;
use App\Events\OrderAfterCreatedEvent;
use App\Events\OrderAfterDeletedEvent;
use App\Events\OrderAfterPrintedEvent;
use App\Events\OrderAfterUpdatedEvent;
use App\Events\ProductStockUpdatedEvent;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Models\Role;
use App\Models\User;
use App\Services\OrdersService;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Broadcast;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;
use Tests\Traits\WithCustomerTest;

/**
 * Covers the guarantees that make it safe to broadcast over Reverb in a live shop.
 *
 * Three things are under test, and each one has already been wrong in this codebase:
 *
 *  - A broadcast must never be able to fail a sale. The queue runs on the sync driver, so
 *    a broadcast happens inline with the order being written.
 *  - Order activity must not go out on a public channel. It used to: the whole Order model
 *    was published to "default-channel", which was harmless only because the driver was
 *    "log" and nothing left the server.
 *  - A private channel must actually be registered and gated. Two order events published to
 *    a channel with no authorization callback, which is a channel nothing can subscribe to.
 */
class RealtimeBroadcastTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication, WithCustomerTest;

    /**
     * The keys an order event is allowed to put on the wire.
     *
     * @var array<int, string>
     */
    private const ALLOWED_ORDER_KEYS = [
        'id',
        'code',
        'payment_status',
        'process_status',
        'delivery_status',
        'total',
        'created_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        ns()->option->set( 'ns_currency_precision', 2 );
        $this->attemptAuthenticate();
    }

    /**
     * Guards the whole policy rather than one event: a broadcast event added later without
     * the guard would make Reverb a hard dependency of selling again, and nothing else in
     * the suite would notice.
     */
    public function test_every_broadcast_event_is_rescued(): void
    {
        $unguarded = [];
        $found = 0;

        foreach ( glob( app_path( 'Events/*.php' ) ) as $file ) {
            $class = 'App\\Events\\' . pathinfo( $file, PATHINFO_FILENAME );

            if ( ! class_exists( $class ) ) {
                continue;
            }

            $interfaces = class_implements( $class );

            if ( ! in_array( ShouldBroadcast::class, $interfaces, true ) ) {
                continue;
            }

            $found++;

            if ( ! in_array( ResilientBroadcast::class, $interfaces, true ) ) {
                $unguarded[] = class_basename( $class );
            }
        }

        $this->assertGreaterThan( 0, $found, 'The scan found no broadcast events, so it is proving nothing.' );
        $this->assertSame(
            [],
            $unguarded,
            'These events can fail the operation that dispatched them: ' . implode( ', ', $unguarded )
        );
    }

    public function test_order_events_never_use_a_public_channel(): void
    {
        $order = $this->givenOrder();

        $events = [
            new OrderAfterCreatedEvent( $order ),
            new OrderAfterUpdatedEvent( $order ),
            new OrderAfterDeletedEvent( $order ),
            new OrderAfterPrintedEvent( $order, 'receipt' ),
        ];

        foreach ( $events as $event ) {
            $channels = collect( $event->broadcastOn() );

            $this->assertNotEmpty( $channels, class_basename( $event ) . ' broadcasts nowhere.' );

            foreach ( $channels as $channel ) {
                $this->assertInstanceOf(
                    PrivateChannel::class,
                    $channel,
                    class_basename( $event ) . ' must not publish order data on a public channel.'
                );

                /**
                 * Laravel prefixes a private channel name with "private-" on the wire, so the
                 * registered name is what is left after it.
                 */
                $this->assertSame( 'private-orders', ( new \ReflectionProperty( Channel::class, 'name' ) )->getValue( $channel ) );
            }
        }
    }

    public function test_an_order_event_carries_a_summary_and_not_the_model(): void
    {
        $order = $this->givenOrder();

        $payload = ( new OrderAfterCreatedEvent( $order ) )->broadcastWith();

        $this->assertArrayHasKey( 'order', $payload );
        $this->assertSame( [ 'order' ], array_keys( $payload ) );

        $keys = array_keys( $payload[ 'order' ] );

        sort( $keys );
        $allowed = self::ALLOWED_ORDER_KEYS;
        sort( $allowed );

        $this->assertSame( $allowed, $keys );

        /**
         * The figures a competitor or a customer on the shop wifi has no business reading.
         * total_cogs is the cost of the goods; author_id and customer_id identify people.
         */
        foreach ( [ 'total_cogs', 'customer_id', 'author_id', 'discount', 'tendered', 'change' ] as $forbidden ) {
            $this->assertArrayNotHasKey( $forbidden, $payload[ 'order' ], $forbidden . ' must not be broadcast.' );
        }

        $this->assertSame( $order->id, $payload[ 'order' ][ 'id' ] );
        $this->assertSame( $order->code, $payload[ 'order' ][ 'code' ] );
    }

    public function test_a_printed_order_event_does_not_broadcast_the_document(): void
    {
        $order = $this->givenOrder();

        /**
         * $doc arrives from the {doc?} segment of the print route, which carries no
         * constraint, so anything a caller puts there would be relayed verbatim to every
         * subscribed dashboard. Only the two documents the printing settings offer pass.
         */
        foreach ( [ 'receipt', 'invoice' ] as $document ) {
            $payload = ( new OrderAfterPrintedEvent( $order, $document ) )->broadcastWith();

            $this->assertSame( $document, $payload[ 'doc' ] );
        }

        foreach ( [ '<script>alert(1)</script>', 'anything-else', [ 'receipt' ], null ] as $rejected ) {
            $payload = ( new OrderAfterPrintedEvent( $order, $rejected ) )->broadcastWith();

            $this->assertNull( $payload[ 'doc' ] );
        }
    }

    public function test_an_order_event_survives_a_payload_that_is_not_a_model(): void
    {
        /**
         * The delete flow hands over a plain object, not an Order. A payload builder that
         * assumed the model would throw from inside the delete.
         */
        $plain = (object) [ 'id' => 42, 'code' => 'PLAIN-1' ];

        $payload = ( new OrderAfterDeletedEvent( $plain ) )->broadcastWith();

        $this->assertSame( 42, $payload[ 'order' ][ 'id' ] );
        $this->assertSame( 'PLAIN-1', $payload[ 'order' ][ 'code' ] );
        $this->assertNull( $payload[ 'order' ][ 'payment_status' ] );
        $this->assertSame( 0.0, $payload[ 'order' ][ 'total' ] );
    }

    public function test_the_event_names_are_explicit(): void
    {
        $order = $this->givenOrder();

        /**
         * Named rather than left to the class name, so a rename or a namespace change cannot
         * silently stop every subscriber from matching.
         */
        $this->assertSame( 'order.created', ( new OrderAfterCreatedEvent( $order ) )->broadcastAs() );
        $this->assertSame( 'order.updated', ( new OrderAfterUpdatedEvent( $order ) )->broadcastAs() );
        $this->assertSame( 'order.deleted', ( new OrderAfterDeletedEvent( $order ) )->broadcastAs() );
        $this->assertSame( 'order.printed', ( new OrderAfterPrintedEvent( $order, 'receipt' ) )->broadcastAs() );
    }

    public function test_the_orders_channel_is_registered(): void
    {
        $registered = collect( Broadcast::getFacadeRoot()->getChannels() )->keys();

        /**
         * A private channel with no authorization callback cannot be subscribed to at all, so
         * a missing registration is a dead feature rather than an error anyone would see.
         */
        $this->assertTrue( $registered->contains( 'orders' ), 'The orders channel has no authorization callback.' );
        $this->assertTrue( $registered->contains( 'stock' ) );
    }

    public function test_the_orders_channel_requires_the_orders_read_permission(): void
    {
        $channel = new OrdersChannel;

        $admin = Role::namespace( Role::ADMIN )->users()->firstOrFail();

        $this->assertTrue( $channel->join( $admin ), 'An administrator must be able to follow order activity.' );

        $outsider = $this->givenUserWithoutOrdersPermission();

        $this->assertFalse(
            $channel->join( $outsider ),
            'A user who cannot read orders must not be able to subscribe to them over a socket either.'
        );
    }

    public function test_the_stock_channel_stays_public_and_minimal(): void
    {
        $event = new ProductStockUpdatedEvent(
            product_id: 1,
            unit_quantity_id: 2,
            unit_id: 3,
            quantity: 4.0,
        );

        $channels = collect( $event->broadcastOn() );

        /**
         * Public on purpose: a product id and a quantity are already on every till screen,
         * and one shared channel avoids fanning the same message out per cashier. This test
         * exists so the payload cannot quietly grow into something that should be private.
         */
        foreach ( $channels as $channel ) {
            $this->assertNotInstanceOf( PrivateChannel::class, $channel );
        }

        $this->assertSame(
            [ 'product_id', 'quantity', 'unit_id', 'unit_quantity_id' ],
            collect( array_keys( $event->broadcastWith() ) )->sort()->values()->toArray()
        );
    }

    public function test_a_sale_completes_when_broadcasting_is_broken(): void
    {
        $this->givenABroadcasterThatAlwaysFails();

        $ordersService = app()->make( OrdersService::class );

        /**
         * The whole point of ShouldRescue. Reverb stops, the shop keeps selling. Without it
         * this call throws a BroadcastException out of the middle of the sale, after the
         * order has already been written, and the cashier rings the sale up twice.
         */
        $result = $ordersService->create( $this->givenOrderPayload() );

        $this->assertSame( 'success', $result[ 'status' ] );
        $this->assertSame( Order::PAYMENT_PAID, $result[ 'data' ][ 'order' ]->payment_status );

        $this->assertDatabaseHas( 'nexopos_orders', [
            'id' => $result[ 'data' ][ 'order' ]->id,
            'payment_status' => Order::PAYMENT_PAID,
        ] );
    }

    public function test_a_failed_broadcast_stops_the_next_ones_from_waiting(): void
    {
        $circuit = app()->make( BroadcastCircuit::class );
        $circuit->reset();

        $attempts = 0;

        $inner = new class( $attempts ) implements Broadcaster
        {
            public function __construct( public int &$attempts ) {}

            public function auth( $request )
            {
                return true;
            }

            public function validAuthenticationResponse( $request, $result )
            {
                return $result;
            }

            public function broadcast( array $channels, $event, array $payload = [] )
            {
                $this->attempts++;

                throw new RuntimeException( 'unreachable' );
            }
        };

        $broadcaster = new CircuitBreakingBroadcaster( $inner, $circuit );

        /**
         * The first publish is attempted and fails. Every publish after it must be skipped
         * without touching the network: broadcasting is inline with the sale, so a sale that
         * fires several events would otherwise pay the connect timeout once per event, for as
         * long as the websocket server stayed down.
         */
        for ( $i = 0; $i < 4; $i++ ) {
            try {
                $broadcaster->broadcast( [ 'stock' ], 'stock.updated', [] );
            } catch ( RuntimeException ) {
                // expected on the first call only
            }
        }

        $this->assertSame( 1, $attempts, 'Only the first publish should reach the network.' );
        $this->assertTrue( $circuit->isOpen() );

        /**
         * Re-thrown rather than swallowed, so rescue() still records it and the operator can
         * see in the log that realtime is down.
         */
        $circuit->reset();
        $this->expectException( RuntimeException::class );
        $broadcaster->broadcast( [ 'stock' ], 'stock.updated', [] );
    }

    public function test_channel_authorization_keeps_working_while_the_circuit_is_open(): void
    {
        $circuit = app()->make( BroadcastCircuit::class );
        $circuit->trip();

        $inner = new class implements Broadcaster
        {
            public function auth( $request )
            {
                return 'authorized';
            }

            public function validAuthenticationResponse( $request, $result )
            {
                return $result;
            }

            public function broadcast( array $channels, $event, array $payload = [] )
            {
                throw new RuntimeException( 'must not be called' );
            }
        };

        $broadcaster = new CircuitBreakingBroadcaster( $inner, $circuit );

        /**
         * Authorization is answered by this application, not by the websocket server. If a
         * brief outage also started rejecting subscriptions, the tills would stop trying to
         * reconnect and realtime would not come back on its own.
         */
        $this->assertSame( 'authorized', $broadcaster->auth( request() ) );

        $circuit->reset();
    }

    /**
     * Points the application at a broadcaster that throws on every publish, the way a dead
     * Reverb server behaves.
     */
    private function givenABroadcasterThatAlwaysFails(): void
    {
        Broadcast::extend( 'always-failing', fn () => new class implements Broadcaster
        {
            public function auth( $request )
            {
                return true;
            }

            public function validAuthenticationResponse( $request, $result )
            {
                return $result;
            }

            public function broadcast( array $channels, $event, array $payload = [] )
            {
                throw new RuntimeException( 'the websocket server is unreachable' );
            }
        } );

        config( [
            'broadcasting.default' => 'always-failing',
            'broadcasting.connections.always-failing' => [ 'driver' => 'always-failing' ],
        ] );

        /**
         * The manager caches drivers, so a connection resolved earlier in the request would
         * otherwise keep being used.
         */
        Broadcast::forgetDrivers();
    }

    private function givenUserWithoutOrdersPermission(): User
    {
        $role = Role::firstOrNew( [ 'namespace' => 'ns.test.no-orders' ] );
        $role->name = 'Test role without orders access';
        $role->namespace = 'ns.test.no-orders';
        $role->description = 'Holds no permissions at all.';
        $role->save();

        $user = new User;
        $user->username = 'no-orders-' . uniqid();
        $user->email = $user->username . '@example.com';
        $user->password = bcrypt( 'password' );
        $user->active = true;
        $user->save();

        /**
         * roles() is a HasManyThrough, so it has no sync(). assignRole() writes the pivot
         * row, which is how the rest of the application does it.
         */
        $user->assignRole( $role->namespace );

        return $user->fresh( 'roles' );
    }

    private function givenOrder(): Order
    {
        $order = Order::first();

        if ( $order instanceof Order ) {
            return $order;
        }

        $result = app()->make( OrdersService::class )->create( $this->givenOrderPayload() );

        return $result[ 'data' ][ 'order' ];
    }

    /**
     * @return array<string, mixed>
     */
    private function givenOrderPayload( float $total = 100.0 ): array
    {
        $unitQuantity = ProductUnitQuantity::whereHas(
            'product',
            fn( $query ) => $query->where( 'type', '!=', Product::TYPE_GROUPED )
                ->where( 'stock_management', Product::STOCK_MANAGEMENT_DISABLED )
        )->with( 'product' )->first();

        $unitQuantity ??= ProductUnitQuantity::whereHas(
            'product',
            fn( $query ) => $query->where( 'type', '!=', Product::TYPE_GROUPED )
        )->with( 'product' )->firstOrFail();

        $customer = Customer::first() ?: $this->attemptCreateCustomer();

        return [
            'customer_id' => $customer->id,
            'type' => [ 'identifier' => Order::TYPE_TAKEAWAY ],
            'discount_type' => null,
            'discount_percentage' => 0,
            'discount' => 0,
            'addresses' => [],
            'author' => User::first()->id,
            'coupons' => [],
            'subtotal' => $total,
            'shipping' => 0,
            'products' => [
                [
                    'product_id' => $unitQuantity->product_id,
                    'quantity' => 1,
                    'unit_price' => $total,
                    'unit_quantity_id' => $unitQuantity->id,
                ],
            ],
            'payments' => [
                [
                    'identifier' => OrderPayment::PAYMENT_CASH,
                    'value' => $total,
                ],
            ],
        ];
    }
}
