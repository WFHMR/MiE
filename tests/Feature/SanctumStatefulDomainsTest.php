<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The dashboard talks to /api/* with the session cookie (bootstrap.ts sets
 * Axios withCredentials and ns.authentication ships a null token), so Sanctum
 * must consider those requests stateful. It only does so when the Referer host
 * matches SANCTUM_STATEFUL_DOMAINS.
 *
 * Sanctum strips the scheme off the Referer before matching and appends "/*" to
 * every configured entry, so an entry written as "http://127.0.0.1:8000/" turns
 * into the pattern "http://127.0.0.1:8000//*" and can never match. That shipped
 * in .env.example, and the symptom is nasty to diagnose: dashboard pages render
 * with 200 while every API call behind them answers 401.
 *
 * Tests cannot catch this through actingAs(), because that sets the user on the
 * web guard directly and Sanctum's guard picks it up whether or not the session
 * middleware ran. The matching rule itself is therefore what gets pinned here.
 */
class SanctumStatefulDomainsTest extends TestCase
{
    private function isStateful( array $stateful, string $referer ): bool
    {
        config( [ 'sanctum.stateful' => $stateful ] );

        $request = Request::create( 'http://127.0.0.1:8000/api/products', 'GET' );
        $request->headers->set( 'referer', $referer );

        return EnsureFrontendRequestsAreStateful::fromFrontend( $request );
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function hostEntries(): array
    {
        return [
            'host with port' => [ [ '127.0.0.1:8000' ], 'http://127.0.0.1:8000/dashboard', true ],
            'host without port matches bare host' => [ [ 'localhost' ], 'http://localhost/dashboard', true ],
            'https referer' => [ [ 'shop.example.com' ], 'https://shop.example.com/dashboard', true ],
            'current host placeholder' => [ [ Sanctum::$currentRequestHostPlaceholder ], 'http://127.0.0.1:8000/dashboard', true ],
            'different port is not stateful' => [ [ '127.0.0.1:9000' ], 'http://127.0.0.1:8000/dashboard', false ],
            'unrelated domain is not stateful' => [ [ 'api.example.com' ], 'http://127.0.0.1:8000/dashboard', false ],
        ];
    }

    #[DataProvider( 'hostEntries' )]
    public function test_host_based_entries_behave_as_expected( array $stateful, string $referer, bool $expected ): void
    {
        $this->assertSame( $expected, $this->isStateful( $stateful, $referer ) );
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function schemePrefixedEntries(): array
    {
        return [
            'http with trailing slash' => [ 'http://127.0.0.1:8000/' ],
            'http without trailing slash' => [ 'http://127.0.0.1:8000' ],
            'https with trailing slash' => [ 'https://127.0.0.1:8000/' ],
        ];
    }

    /**
     * Guards the exact mistake that shipped in .env.example.
     */
    #[DataProvider( 'schemePrefixedEntries' )]
    public function test_entries_written_as_urls_never_match( string $entry ): void
    {
        $this->assertFalse(
            $this->isStateful( [ $entry ], 'http://127.0.0.1:8000/dashboard' ),
            sprintf( 'Sanctum strips the scheme before matching, so "%s" cannot make a request stateful.', $entry )
        );
    }

    /**
     * A request with neither Referer nor Origin cannot be attributed to the
     * frontend, which is what keeps token based clients working.
     */
    public function test_a_request_without_referer_is_not_stateful(): void
    {
        config( [ 'sanctum.stateful' => [ '127.0.0.1:8000' ] ] );

        $request = Request::create( 'http://127.0.0.1:8000/api/products', 'GET' );

        $this->assertFalse( EnsureFrontendRequestsAreStateful::fromFrontend( $request ) );
    }

    /**
     * The shipped example must stay usable out of the box.
     */
    public function test_the_env_example_default_makes_the_serving_host_stateful(): void
    {
        $line = collect( file( base_path( '.env.example' ), FILE_IGNORE_NEW_LINES ) )
            ->first( fn ( string $line ) => str_starts_with( trim( $line ), 'SANCTUM_STATEFUL_DOMAINS=' ) );

        $this->assertNotNull( $line, 'SANCTUM_STATEFUL_DOMAINS is missing from .env.example.' );

        $value = trim( explode( '=', $line, 2 )[ 1 ], " \"'" );
        $entries = array_filter( array_map( 'trim', explode( ',', $value ) ) );

        $this->assertNotEmpty( $entries );

        foreach ( $entries as $entry ) {
            $this->assertStringNotContainsString(
                '://',
                $entry,
                'Entries must be host[:port]; a scheme makes the entry unmatchable.'
            );
        }

        $this->assertTrue(
            $this->isStateful( $entries, 'http://127.0.0.1:8000/dashboard' ),
            'A default install served on 127.0.0.1:8000 would answer 401 on every API call.'
        );
    }
}
