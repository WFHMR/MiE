<?php

namespace App\Listeners;

use App\Events\RenderFooterEvent;
use Carbon\Carbon;

class RenderFooterEventListener
{
    public function handle( RenderFooterEvent $event )
    {
        /**
         * Without a destination there is nothing to send, so the script is not
         * injected at all rather than having every page fire a request that
         * cannot succeed.
         */
        if ( empty( config( 'nexopos.telemetry.url' ) ) ) {
            return;
        }

        $lastSent = ns()->option->get( 'ns_telemetry_last_sent' );
        $needsSend = empty( $lastSent ) || Carbon::parse( $lastSent )->diffInHours( now() ) >= 24;

        if ( $needsSend ) {
            $event->output->addView( 'common.telemetry-script' );
        }
    }
}
