<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Services\MenuService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * รายงานการจัดซื้อ — the report existed, nobody could find it.
 *
 * PurchasingWorkflowController::report() has been serving totals by supplier, by product and by
 * period, with a CSV export, for some time. Its only entry in the menu was under จัดซื้อ, so to a
 * shop reading its own list of reports the purchase report was missing: nobody looks under
 * purchasing for a report. It is now listed under รายงาน as well — the same route, the same
 * permission, one page reachable from both places it is expected to be.
 *
 * The security section is pinned in the same file because it is the same kind of fault: the
 * password policy page and the audit trail are of no use to anybody who cannot find them.
 */
class PurchaseReportReachableTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    /**
     * The report is scoped to a branch, and refuses with 403 when the branch cannot be resolved —
     * so the branch the person is standing in has to be set up the way a real request has it.
     */
    private function standInABranch(): User
    {
        $admin = $this->attemptGetAnyUserFromRole( 'admin' );

        $this->actingAs( $admin, 'web' );

        $branch = Branch::active()->first() ?: Branch::create( [
            'code' => 'RPT' . Str::upper( Str::random( 6 ) ),
            'name' => 'สาขาทดสอบรายงานจัดซื้อ',
            'is_active' => true,
            'author_id' => $admin->id,
        ] );

        session( [ 'selected_branch_id' => $branch->id ] );

        return $admin;
    }

    public function test_the_purchase_report_answers(): void
    {
        $this->standInABranch();

        $this->get( route( 'ns.purchasing.report' ) )
            ->assertStatus( 200 )
            ->assertSee( 'รายงานจัดซื้อ', false );
    }

    public function test_the_purchase_report_exports_a_spreadsheet(): void
    {
        $this->standInABranch();

        $response = $this->get( route( 'ns.purchasing.report' ) . '?format=csv' );

        $response->assertStatus( 200 );

        $this->assertStringContainsString(
            'text/csv',
            (string) $response->headers->get( 'content-type' ),
            'the export no longer returns a spreadsheet'
        );
    }

    public function test_the_report_is_listed_in_the_reports_menu(): void
    {
        $this->standInABranch();

        $this->assertTrue(
            $this->menuHas( 'reports', route( 'ns.purchasing.report' ) ),
            'The purchase report is not listed under รายงาน, which is the only place the shop looks for it.'
        );
    }

    public function test_the_security_section_lists_its_three_screens(): void
    {
        $this->standInABranch();

        foreach ( [
            ns()->url( '/dashboard/users/roles' ),
            ns()->url( '/dashboard/settings/password' ),
            ns()->url( '/dashboard/audit-trail' ),
        ] as $href ) {
            $this->assertTrue(
                $this->menuHas( 'security', $href ),
                'The security menu does not list ' . $href . ', so the screen cannot be reached without typing the address.'
            );
        }
    }

    /**
     * Reads the menu the way the sidebar does, so a permission that hides the entry fails this too.
     */
    private function menuHas( string $menuIdentifier, string $href ): bool
    {
        $menus = app()->make( MenuService::class )->getMenus();

        $menu = $menus[ $menuIdentifier ] ?? null;

        if ( $menu === null ) {
            return false;
        }

        return collect( $menu[ 'childrens' ] ?? [] )
            ->contains( fn ( $submenu ): bool => ( $submenu[ 'href' ] ?? null ) === $href );
    }
}
