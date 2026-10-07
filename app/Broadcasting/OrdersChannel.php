<?php

namespace App\Broadcasting;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Authorizes the channel that carries order activity to open dashboards.
 *
 * Gated on the same permission as the orders list itself. The payload is a trimmed order
 * summary, so a user who can subscribe here can already see all of it by loading the page —
 * but the gate has to be here anyway, because without it the channel is either public (any
 * browser receives every sale) or unregistered (nothing receives anything).
 */
class OrdersChannel
{
    public function __construct()
    {
        //
    }

    /**
     * Authenticate the user's access to the channel.
     */
    public function join( User $user ): bool
    {
        /**
         * Checked against the user the broadcast auth request resolved, not against
         * Auth::user(), so the answer cannot drift from whoever the request authenticated.
         */
        return Gate::forUser( $user )->allows( 'nexopos.read.orders' );
    }
}
