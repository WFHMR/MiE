<?php

namespace Tests\Feature;

use App\Classes\Menu;
use App\Services\MenuService;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class StaffDashboardMenuTest extends TestCase
{
    use WithAuthentication;

    public function test_empty_optional_children_are_omitted_without_changing_menu_permissions(): void
    {
        $item = Menu::item( 'Restricted', 'restricted', '/restricted', permissions: ['restricted.permission'] );
        $this->assertSame( $item, Menu::childrens( [], $item, [] ) );
        $this->assertSame( [], Menu::childrens( [], [] ) );
    }

    public function test_staff_menu_builds_without_admin_trial_entry(): void
    {
        $this->actingAs( $this->attemptGetAnyUserFromRole( 'nexopos.store.cashier' ), 'web' );
        $menus = app( MenuService::class )->getMenus();
        $this->assertNotEmpty( $menus );
        foreach ( $menus as $menu ) {
            $this->assertArrayNotHasKey( 'purchase-trials', $menu['childrens'] );
        }
        $this->get( '/dashboard/purchase-trials' )->assertForbidden();
    }

    public function test_admin_menu_keeps_trial_entry(): void
    {
        $this->actingAs( $this->attemptGetAnyUserFromRole( 'admin' ), 'web' );
        $menus = app( MenuService::class )->getMenus();
        $children = [];
        foreach ( $menus as $menu ) {
            $children = array_merge( $children, $menu['childrens'] );
        }
        $this->assertArrayHasKey( 'purchase-trials', $children );
    }
}
