<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * Stateless clients (mobile apps, REST integrations, anything calling /api
     * from outside a stateful domain) reach this method without a session. Touching
     * the session store unconditionally used to raise "Session store not set on
     * request", turning every unauthenticated API call into a 500 instead of a 401.
     *
     * @param  Request     $request
     * @return string|null
     */
    protected function redirectTo( $request )
    {
        /**
         * JSON clients expect a 401 rather than a redirect, so returning null
         * lets the exception handler produce the unauthenticated response.
         */
        if ( $request->expectsJson() ) {
            return null;
        }

        /**
         * Remembering the intended URL only makes sense when a session exists.
         * Stateless API clients have none, and writing to the session store in
         * that case raised "Session store not set on request".
         */
        if ( $request->hasSession() ) {
            $request->session()->put( 'redirect', $request->fullUrl() );
        }

        /**
         * A URL must always be returned for non JSON requests. Returning null
         * makes the framework fall back to route( 'login' ), which this
         * application does not define, turning the rejection into a 500.
         */
        return ns()->route( 'ns.login', [
            'redirect' => $request->fullUrl(),
        ] );
    }
}
