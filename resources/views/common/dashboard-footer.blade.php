<?php

use App\Services\Options;
use App\Classes\Output;
use App\Classes\Hook;
use Illuminate\Support\Facades\Cookie;

$options            =   app()->make( Options::class );

$authentication     =   [
    'token'         =>  Cookie::get( 'ns_token' ),
    'csrf'          =>  csrf_token()
];

$json               =   [
    'ns_currency_symbol'                =>  $options->get( 'ns_currency_symbol', '฿' ),
    'ns_currency_iso'                   =>  $options->get( 'ns_currency_iso', 'THB' ),
    'ns_currency_position'              =>  $options->get( 'ns_currency_position', 'before' ),
    'ns_currency_thousand_separator'    =>  $options->get( 'ns_currency_thousand_separator', ',' ),
    'ns_currency_decimal_separator'     =>  $options->get( 'ns_currency_decimal_separator', '.' ),
    'ns_currency_precision'             =>  $options->get( 'ns_currency_precision', '0' ),
    'ns_currency_prefered'              =>  $options->get( 'ns_currency_prefered', 'iso' ),
];
?>
<script type="text/javascript">
ns.currency         =   <?php echo json_encode( $json );?>;
ns.authentication   =   <?php echo json_encode( $authentication );?>;
ns.base_url         =   '{{ url( "/" ) }}';
</script>
{{--
    Echo is created when the application actually broadcasts over Reverb and credentials
    are present. Nothing here is required for the shop to sell: if Reverb is not running
    the connection simply never opens, every subscriber stays quiet, and the pages carry on
    working the way they did before realtime existed.

    Reverb being down cannot fail a sale either — see App\Broadcasting\ResilientBroadcast.
--}}
@if( env( 'BROADCAST_DRIVER' ) === 'reverb' && ! empty( env( 'REVERB_APP_KEY' ) ) )
<script>
document.addEventListener( 'DOMContentLoaded', () => {
    /**
     * REVERB_HOST is the host the *browser* dials, which is not the host the server binds
     * to (that is REVERB_SERVER_HOST). Leaving it empty means "whatever host served this
     * page", which is what makes the same .env work on 127.0.0.1 during development and
     * from the tills over the shop LAN without being edited — the same reasoning as the
     * empty SESSION_DOMAIN and the Sanctum current-request-host placeholder.
     */
    const reverbHost    =   '{{ env( 'REVERB_HOST' ) }}' || window.location.hostname;
    const forceTLS      =   {{ ( env( 'REVERB_SCHEME' ) ?? 'http' ) === 'https' ? 'true' : 'false' }};

    window.Echo = new EchoClass({
        broadcaster: 'reverb',
        key: '{{ env( 'REVERB_APP_KEY' ) }}',
        wsHost: reverbHost,
        wsPort: {{ (int) ( env( 'REVERB_PORT', 8080 ) ) }},
        wssPort: {{ (int) ( env( 'REVERB_PORT', 8080 ) ) }},
        forceTLS: forceTLS,

        /**
         * Both transports are offered so a TLS deployment behind a reverse proxy works
         * without a second configuration switch. forceTLS decides which one is used.
         */
        enabledTransports: [ 'ws', 'wss' ],

        /**
         * Private channels post to /broadcasting/auth, which sits behind the web middleware
         * group and therefore behind CSRF verification. The layout ships no csrf-token meta
         * tag, so Echo cannot discover the token on its own and it has to be handed over
         * explicitly — without this every private subscription answers 419 and the
         * notification bell and the live order list silently never connect.
         *
         * authEndpoint is built from the application URL rather than hardcoded, because
         * the application can be served from a subdirectory.
         */
        authEndpoint: '{{ ns()->url( '/broadcasting/auth' ) }}',
        auth: {
            headers: {
                'X-CSRF-TOKEN': ns.authentication.csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
        },
    });
});
</script>
@endif
@vite([ 'resources/ts/bootstrap.ts' ])
@include( 'layout._footer-injection' )
@yield( 'layout.dashboard.footer.inject' )
@vite([ 'resources/ts/app-init.ts' ])
@vite([ 'resources/ts/app.ts' ])
