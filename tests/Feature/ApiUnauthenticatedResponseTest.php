<?php

namespace Tests\Feature;

use Illuminate\Auth\AuthenticationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Stateless API clients (mobile apps, REST integrations) reach the auth
 * middleware without a session. Reading the session store in that situation
 * used to raise "Session store not set on request", so every unauthenticated
 * API call answered 500 with a stack trace instead of a clean rejection.
 */
class ApiUnauthenticatedResponseTest extends TestCase
{
    /**
     * @return array<int, array<int, string>>
     */
    public static function protectedApiRoutes(): array
    {
        return [
            [ 'api/products' ],
            [ 'api/customers' ],
            [ 'api/orders' ],
            [ 'api/users' ],
            [ 'api/dashboard/day' ],
        ];
    }

    #[DataProvider( 'protectedApiRoutes' )]
    public function test_unauthenticated_json_request_is_rejected_with_401( string $uri ): void
    {
        $response = $this->getJson( $uri );

        $response->assertStatus( 401 );
    }

    #[DataProvider( 'protectedApiRoutes' )]
    public function test_unauthenticated_request_without_accept_header_does_not_crash( string $uri ): void
    {
        $response = $this->get( $uri );

        $this->assertNotSame(
            500,
            $response->status(),
            "GET {$uri} without an Accept header must not raise a server error. It should reject the request instead."
        );

        $this->assertContains(
            $response->status(),
            [ 302, 401 ],
            "GET {$uri} without an Accept header should redirect or answer 401, got {$response->status()}."
        );
    }

    public function test_unauthenticated_request_without_accept_header_does_not_touch_the_session(): void
    {
        /**
         * The regression this guards against surfaced as a RuntimeException
         * rather than an HTTP level failure, so assert on the exception too.
         */
        $this->withoutExceptionHandling( [ AuthenticationException::class ] );

        try {
            $response = $this->get( 'api/products' );

            $this->assertContains( $response->status(), [ 302, 401 ] );
        } catch ( \RuntimeException $exception ) {
            $this->fail( 'The auth middleware touched the session on a stateless request: ' . $exception->getMessage() );
        }
    }
}
