<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class TelemetryTest extends TestCase
{
    use WithAuthentication;

    /**
     * The endpoint used to post to a placeholder URL, which threw and turned
     * into a 500 on every dashboard page.
     */
    public function test_telemetry_succeeds_quietly_when_no_endpoint_is_configured(): void
    {
        $this->attemptAuthenticate();

        Config::set( 'nexopos.telemetry.url', null );

        Http::fake();

        $response = $this->json( 'POST', '/api/system/telemetry' );

        $response->assertStatus( 200 );
        $response->assertJsonPath( 'status', 'success' );

        Http::assertNothingSent();
    }

    public function test_telemetry_posts_to_the_configured_endpoint(): void
    {
        $this->attemptAuthenticate();

        Config::set( 'nexopos.telemetry.url', 'https://telemetry.test/collect' );

        Http::fake( [
            'telemetry.test/*' => Http::response( [ 'ok' => true ] ),
        ] );

        $response = $this->json( 'POST', '/api/system/telemetry' );

        $response->assertStatus( 200 );
        $response->assertJsonPath( 'status', 'success' );

        Http::assertSent( function ( $request ) {
            return $request->url() === 'https://telemetry.test/collect'
                && isset( $request[ 'version' ] )
                && isset( $request[ 'host' ] );
        } );
    }

    /**
     * A telemetry ping that cannot be delivered is not a server error. Reporting
     * it as one put a failing request on every page of the dashboard.
     */
    public function test_telemetry_does_not_return_a_server_error_when_delivery_fails(): void
    {
        $this->attemptAuthenticate();

        Config::set( 'nexopos.telemetry.url', 'not-a-valid-url' );

        $response = $this->json( 'POST', '/api/system/telemetry' );

        $response->assertStatus( 200 );
        $response->assertJsonPath( 'status', 'error' );
    }
}
