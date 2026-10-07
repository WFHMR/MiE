<?php

namespace App\Broadcasting;

use Illuminate\Support\Facades\Cache;

/**
 * Remembers that the websocket server is unreachable, so the till stops waiting on it.
 *
 * Broadcasting happens inline with the sale because the queue runs on the sync driver.
 * ResilientBroadcast means a dead Reverb cannot fail a sale, and the connect timeout in
 * config/broadcasting.php caps a single attempt at about a second — but a sale fires several
 * events, so with Reverb down every sale would still stall for a few seconds, for as long as
 * it stays down.
 *
 * A cashier will not tolerate that all afternoon. After the first failure this stops the
 * attempts for a short window, so the shop pays the timeout once a minute instead of once
 * per event. When Reverb comes back the window expires and broadcasting resumes on its own,
 * which matters because nobody is watching for it to be re-enabled.
 */
class BroadcastCircuit
{
    private const CACHE_KEY = 'ns.broadcast.unreachable';

    /**
     * How long to stop trying after a failure.
     *
     * Short on purpose. systemd and supervisor both restart Reverb within a few seconds, so
     * a long window would keep realtime off well after the server was healthy again.
     */
    private const COOLDOWN_SECONDS = 30;

    /**
     * Whether broadcasting should be skipped right now.
     */
    public function isOpen(): bool
    {
        return Cache::get( self::CACHE_KEY, false ) === true;
    }

    /**
     * Records that a broadcast failed to reach the server.
     */
    public function trip(): void
    {
        Cache::put( self::CACHE_KEY, true, self::COOLDOWN_SECONDS );
    }

    /**
     * Clears the cooldown. Used when a broadcast succeeds, and by the tests.
     */
    public function reset(): void
    {
        Cache::forget( self::CACHE_KEY );
    }
}
