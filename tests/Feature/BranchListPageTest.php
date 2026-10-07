<?php

namespace Tests\Feature;

use App\Crud\BranchCrud;
use App\Services\MenuService;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * The branch pages the side menu links to.
 *
 * The menu pointed at /dashboard/crud/ns.branches. There is no generic
 * dashboard/crud/{namespace} route in this application — every resource is given its own
 * dashboard route — so both branch menu entries answered 404 for every user including the
 * administrator, while BranchCrud::getLinks() had been declaring /dashboard/branches all along.
 *
 * These assertions are made against the route table rather than over HTTP because the defect
 * was a missing route, and because dashboard pages need a session the API test harness does
 * not carry.
 */
class BranchListPageTest extends TestCase
{
    use WithAuthentication;

    /**
     * @return list<string> the dashboard paths, without leading slash
     */
    private function menuPaths(): array
    {
        $menus = app()->make( MenuService::class )->getMenus();

        $this->assertArrayHasKey( 'branches', $menus, 'The branch menu is gone.' );

        return array_values( array_map(
            static fn ( array $child ): string => ltrim( (string) parse_url( $child[ 'href' ], PHP_URL_PATH ), '/' ),
            $menus[ 'branches' ][ 'childrens' ]
        ) );
    }

    private function assertPathIsRouted( string $path ): void
    {
        $matched = null;

        foreach ( Route::getRoutes() as $route ) {
            if ( ! in_array( 'GET', $route->methods(), true ) ) {
                continue;
            }

            $pattern = '#^' . preg_replace( '#\\\{\w+\??\\\}#', '[^/]+', preg_quote( $route->uri(), '#' ) ) . '$#';

            if ( preg_match( $pattern, $path ) === 1 ) {
                $matched = $route;

                break;
            }
        }

        $this->assertNotNull( $matched, sprintf( 'No GET route answers "%s".', $path ) );
    }

    public function test_every_branch_menu_entry_points_at_a_registered_route(): void
    {
        $this->attemptAuthenticate();

        $paths = $this->menuPaths();

        $this->assertNotEmpty( $paths, 'The branch menu has no entries.' );

        foreach ( $paths as $path ) {
            $this->assertPathIsRouted( $path );
        }
    }

    /**
     * The menu must not go back to the generic path, which nothing serves.
     */
    public function test_the_menu_does_not_point_at_the_generic_crud_path(): void
    {
        $this->attemptAuthenticate();

        foreach ( $this->menuPaths() as $path ) {
            $this->assertStringNotContainsString( 'crud/ns.branches', $path );
        }
    }

    /**
     * The links the table itself renders have to be routed too, or its "create" button and its
     * row actions lead nowhere.
     */
    public function test_the_links_the_crud_declares_are_registered(): void
    {
        $this->attemptAuthenticate();

        $links = ( new BranchCrud )->getLinks();

        foreach ( [ 'list', 'create', 'edit' ] as $key ) {
            $this->assertArrayHasKey( $key, $links );

            $path = ltrim( (string) parse_url( $links[ $key ], PHP_URL_PATH ), '/' );

            $this->assertPathIsRouted( str_replace( '{id}', '1', $path ) );
        }
    }
}
