<?php

namespace Tests\Feature;

use App\Exceptions\NotAllowedException;
use App\Services\MarketplaceService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * MARKETPLACE_DOMAIN is an optional integration and ships unset. Every call
 * site used to fall back to "#", so Guzzle received "#/api/nexoplatform/..."
 * and raised 'The scheme "" is not allowed by the protocols request option'.
 * Both marketplace endpoints answered 500 as a result.
 *
 * The listing endpoints must now degrade to an empty result, and the flows that
 * genuinely need the remote host must fail with a message that names the
 * missing variable.
 */
class MarketplaceNotConfiguredTest extends TestCase
{
    use WithAuthentication;

    private ?string $originalDomain = null;

    protected function setUp(): void
    {
        parent::setUp();

        $existing = getenv( 'MARKETPLACE_DOMAIN' );
        $this->originalDomain = $existing === false ? null : $existing;

        /**
         * Any outgoing call would mean the guard did not kick in.
         */
        Http::preventStrayRequests();
    }

    /**
     * The env value is process wide, so it has to be put back or the following
     * tests in this process would inherit it.
     */
    protected function tearDown(): void
    {
        $this->withMarketplaceDomain( $this->originalDomain );

        parent::tearDown();
    }

    private function withMarketplaceDomain( ?string $domain ): void
    {
        if ( $domain === null ) {
            unset( $_ENV[ 'MARKETPLACE_DOMAIN' ], $_SERVER[ 'MARKETPLACE_DOMAIN' ] );
            putenv( 'MARKETPLACE_DOMAIN' );

            return;
        }

        $_ENV[ 'MARKETPLACE_DOMAIN' ] = $domain;
        $_SERVER[ 'MARKETPLACE_DOMAIN' ] = $domain;
        putenv( 'MARKETPLACE_DOMAIN=' . $domain );
    }

    /**
     * @return array<string, array<int, string|null>>
     */
    public static function unusableDomains(): array
    {
        return [
            'unset' => [ null ],
            'empty' => [ '' ],
            'legacy hash fallback' => [ '#' ],
            'missing scheme' => [ 'my.nexopos.com' ],
        ];
    }

    #[DataProvider( 'unusableDomains' )]
    public function test_service_reports_itself_as_not_configured( ?string $domain ): void
    {
        $this->withMarketplaceDomain( $domain );

        $this->assertFalse( app( MarketplaceService::class )->isConfigured() );
    }

    public function test_service_reports_itself_as_configured_with_a_full_url(): void
    {
        $this->withMarketplaceDomain( 'https://my.nexopos.com/' );

        $this->assertTrue( app( MarketplaceService::class )->isConfigured() );
    }

    public function test_modules_endpoint_returns_an_empty_result_instead_of_failing(): void
    {
        $this->withMarketplaceDomain( null );
        $this->attemptAuthenticate();

        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'GET', 'api/marketplace/modules' );

        $response->assertStatus( 200 );
        $response->assertJsonPath( 'data', [] );
        $response->assertJsonPath( 'meta.current_page', 1 );
        $response->assertJsonPath( 'meta.last_page', 1 );
        $response->assertJsonPath( 'meta.total', 0 );
        $this->assertStringContainsString( 'MARKETPLACE_DOMAIN', $response->json( 'message' ) );
    }

    public function test_categories_endpoint_returns_an_empty_list_instead_of_failing(): void
    {
        $this->withMarketplaceDomain( null );
        $this->attemptAuthenticate();

        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'GET', 'api/marketplace/categories' );

        $response->assertStatus( 200 );
        $response->assertExactJson( [] );
    }

    public function test_marketplace_dashboard_page_still_renders(): void
    {
        $this->withMarketplaceDomain( null );
        $user = $this->attemptGetAnyUserFromRole();

        $response = $this->actingAs( $user )
            ->json( 'GET', 'dashboard/modules/marketplace' );

        $response->assertStatus( 200 );
        $response->assertSee( 'dashboard-body' );
    }

    public function test_connection_test_fails_quietly_without_notifying(): void
    {
        $this->withMarketplaceDomain( null );
        $this->attemptAuthenticate();

        $this->assertFalse( app( MarketplaceService::class )->testConnection() );
    }

    public function test_authorize_redirects_back_rather_than_leaving_the_app(): void
    {
        $this->withMarketplaceDomain( null );
        $user = $this->attemptGetAnyUserFromRole();

        $response = $this->actingAs( $user )
            ->get( route( ns()->routeName( 'ns.oauth.mynexopos.authorize' ) ) );

        $response->assertRedirect( route( 'ns.dashboard.modules-marketplace' ) );
        $response->assertSessionHas( 'error' );
    }

    public function test_licenses_endpoint_names_the_missing_variable(): void
    {
        $this->withMarketplaceDomain( null );
        $this->attemptAuthenticate();

        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'GET', 'api/marketplace/licenses/1' );

        $response->assertStatus( 403 );
        $this->assertStringContainsString( 'MARKETPLACE_DOMAIN', $response->json( 'message' ) );
    }

    public function test_download_endpoint_names_the_missing_variable(): void
    {
        $this->withMarketplaceDomain( null );
        $this->attemptAuthenticate();

        $this->expectException( NotAllowedException::class );

        app( MarketplaceService::class )->addToCart( 1 );
    }
}
