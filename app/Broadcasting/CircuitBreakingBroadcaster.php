<?php

namespace App\Broadcasting;

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Throwable;

/**
 * Wraps the real broadcaster so a dead websocket server is only discovered once per cooldown
 * rather than once per event.
 *
 * Applied as a decorator over the configured driver rather than as a broadcastWhen() on each
 * event, so it also covers events added later and events published by modules — nothing has
 * to opt in.
 *
 * The exception is deliberately re-thrown. Swallowing it here would hide the failure from
 * rescue(), which is what writes it to the log, and from ProductService::announceStockChange,
 * which has its own handling. This class decides *whether to try*, not whether a failure
 * matters.
 */
class CircuitBreakingBroadcaster implements Broadcaster
{
    public function __construct(
        private Broadcaster $broadcaster,
        private BroadcastCircuit $circuit,
    ) {}

    /**
     * Channel authorization is answered by this application, not by the websocket server, so
     * it must keep working while the circuit is open. Otherwise a brief Reverb outage would
     * also start rejecting subscription attempts, and the tills would give up reconnecting.
     */
    public function auth( $request )
    {
        return $this->broadcaster->auth( $request );
    }

    public function validAuthenticationResponse( $request, $result )
    {
        return $this->broadcaster->validAuthenticationResponse( $request, $result );
    }

    public function broadcast( array $channels, $event, array $payload = [] )
    {
        if ( $this->circuit->isOpen() ) {
            return;
        }

        try {
            $this->broadcaster->broadcast( $channels, $event, $payload );
        } catch ( Throwable $exception ) {
            $this->circuit->trip();

            throw $exception;
        }
    }

    /**
     * Everything else goes straight through.
     *
     * BroadcastManager forwards unknown static calls to whatever driver is configured, so the
     * decorator has to answer more than the three methods on the Broadcaster contract —
     * channel() registrations from routes/channels.php arrive this way, and so does
     * getChannels(). Forwarding rather than listing them keeps this from breaking when the
     * framework adds another.
     *
     * @param array<int, mixed> $parameters
     */
    public function __call( string $method, array $parameters ): mixed
    {
        return $this->broadcaster->{$method}( ...$parameters );
    }
}
