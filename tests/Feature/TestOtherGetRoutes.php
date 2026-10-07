<?php

namespace Tests\Feature;

use App\Models\Register;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class TestOtherGetRoutes extends TestCase
{
    use WithAuthentication;

    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_all_api_routes()
    {
        $this->attemptAuthenticate();

        $routes = Route::getRoutes();

        foreach ( $routes as $route ) {
            $uri = $route->uri();

            if ( in_array( 'GET', $route->methods() ) ) {
                /**
                 * We'll test both known API and dashboard to see if
                 * there is any error thrown.
                 */
                if ( preg_match( '/^api\//', $uri ) && ! preg_match( '/\{\w+\??\}/', $uri ) ) {
                    $response = $this->withSession( $this->app[ 'session' ]->all() )
                        ->json( 'GET', $uri );

                    /**
                     * Route that allow exception
                     */
                    if ( in_array( $response->status(), [ 200, 403 ] ) ) {
                        if ( $uri === 'api/promotions/products' ) {
                            /**
                             * A product search for the coupon form, and it resolves the branch from
                             * the session. A browser always has one — SetDefaultBranch fills it on
                             * every authenticated request — but this loop signs in without going
                             * through that, so the precondition is read rather than assumed, the
                             * same way the register endpoint below is handled.
                             */
                            $response->assertStatus(
                                empty( session( 'selected_branch_id' ) ) ? 403 : 200
                            );
                        } elseif ( in_array( $uri, [
                            'api/cash-registers/used',
                        ] ) ) {
                            /**
                             * This endpoint answers 403 only when the acting user has no
                             * open register. The suite shares a single database and
                             * attemptAuthenticate() picks a random administrator, so the
                             * precondition has to be read instead of assumed, otherwise the
                             * assertion passes or fails depending on which user was drawn.
                             */
                            $response->assertStatus(
                                Register::opened()->usedBy( Auth::id() )->exists() ? 200 : 403
                            );
                        } else {
                            /**
                             * If $uri include /api/setup we should expect a failure
                             */
                            if ( strpos( $uri, 'api/setup' ) !== false ) {
                                $response->assertStatus( 403 );
                            } else {
                                /**
                                 * The route belongs in the message here too. This loop walks every
                                 * API GET route in the installation, and a bare status mismatch
                                 * leaves the next person to find which one by bisecting.
                                 */
                                $this->assertSame(
                                    200,
                                    $response->status(),
                                    sprintf( '%s answered %d instead of 200.', $uri, $response->status() )
                                );
                            }
                        }
                    } else {
                        throw new Exception( 'Not supported status detected.' );
                    }
                }
            }
        }
    }

    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_dashboard_get_routes()
    {
        $routes = Route::getRoutes();
        $user = $this->attemptGetAnyUserFromRole();

        foreach ( $routes as $route ) {
            $uri = $route->uri();

            if ( in_array( 'GET', $route->methods() ) ) {
                /**
                 * We'll test both known API and dashboard to see if
                 * there is any error thrown.
                 */
                if ( ( strstr( $uri, 'dashboard' ) ) && ! strstr( $uri, 'api/' ) && ! preg_match( '/\{\w+\??\}/', $uri ) ) {
                    $response = $this->actingAs( $user )
                        ->json( 'GET', $uri );

                    if ( $response->status() === 302 ) {
                        /**
                         * The route belongs in the message for the same reason it does below: a
                         * bare "false is not true" leaves the next person to find which of a
                         * hundred routes redirected by bisecting.
                         */
                        $this->assertTrue(
                            in_array( $uri, [
                                'dashboard/accounting/transactions/create',
                                'dashboard/oauth/mynexopos/authorize',
                            ] ),
                            sprintf( '%s redirected, and it is not one of the routes allowed to.', $uri )
                        );
                    } else {
                        if ( $response->status() == 200 ) {
                            if ( $uri === 'dashboard/pos' ) {
                                $response->assertSee( 'ns-pos' ); // pos component
                            } else {
                                /**
                                 * Named, like the statuses above: "the response does not contain
                                 * dashboard-body" says nothing about which of roughly a hundred
                                 * pages rendered outside the dashboard layout.
                                 */
                                /**
                                 * A few dashboard GET routes hand back a file rather than a page.
                                 * Asserting the dashboard layout on a CSV download is asserting
                                 * the wrong thing about a route that works; what matters for these
                                 * is that they answered at all, which the status already said.
                                 */
                                $servesAFile = in_array( $uri, [
                                    'dashboard/customers/import-members/template',
                                ] );

                                if ( ! $servesAFile ) {
                                    $this->assertStringContainsString(
                                        'dashboard-body',
                                        $response->getContent(),
                                        sprintf( '%s answered 200 but did not render inside the dashboard layout.', $uri )
                                    );
                                }
                            }
                        } elseif ( $response->status() === 422 ) {
                            /**
                             * Named here for the same reason as the two cases around it: without
                             * the route, a failure says only that one of roughly a hundred pages
                             * rejected its own empty query string.
                             */
                            $this->assertTrue(
                                in_array( $uri, [
                                    'dashboard/oauth/mynexopos/callback',

                                    /**
                                     * A JSON type-ahead for the quotation form rather than a page,
                                     * and it requires the search term and the kind of record being
                                     * looked for. Refusing a request that carries neither is the
                                     * endpoint working; the assumption that does not hold is this
                                     * loop's, that every dashboard GET renders a page.
                                     */
                                    'dashboard/sales/lookup',

                                    /**
                                     * The till asks this one how many points a named customer
                                     * holds, so it requires the customer. Same shape as the
                                     * lookup above: an endpoint, not a page.
                                     */
                                    'dashboard/customers/branch-points/context',
                                ] ),
                                sprintf( '%s answered 422, and it is not one of the routes allowed to.', $uri )
                            );
                        } else {
                            /**
                             * The route and the status belong in the message. Without them this
                             * says only that one of roughly a hundred routes answered something
                             * unexpected, which leaves the next person to find it by bisecting.
                             */
                            throw new Exception( sprintf(
                                'Not supported status detected: %s answered %d.',
                                $uri,
                                $response->status()
                            ) );
                        }
                    }
                }
            }
        }
    }
}
