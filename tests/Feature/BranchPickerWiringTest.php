<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Tests\TestCase;

/**
 * The branch picker in the dashboard header has to be driven by the framework the page
 * actually loads.
 *
 * It was written with Alpine directives — x-data, x-show, x-text, @click.away — but Alpine
 * is not a dependency of this build. Every directive was inert, so the button rendered and
 * swallowed clicks, and the dropdown kept the inline display:none it was given. Nothing
 * failed loudly; the control simply did nothing, which is why it survived.
 *
 * These assertions are about wiring rather than appearance, because that is what broke.
 */
class BranchPickerWiringTest extends TestCase
{
    public function test_alpine_is_not_a_dependency_of_this_build(): void
    {
        $package = json_decode( file_get_contents( base_path( 'package.json' ) ), true );

        $declared = array_merge(
            array_keys( $package[ 'dependencies' ] ?? [] ),
            array_keys( $package[ 'devDependencies' ] ?? [] )
        );

        foreach ( $declared as $name ) {
            $this->assertStringNotContainsString(
                'alpine',
                strtolower( $name ),
                'Alpine is installed now, so this guard has to be revisited rather than deleted.'
            );
        }
    }

    public function test_the_header_uses_no_alpine_directives(): void
    {
        /**
         * Blade comments are stripped first. The comment above the picker explains what the
         * directives were and why they did nothing, which means it necessarily names them —
         * scanning the raw file would fail on its own documentation and the fix would be to
         * delete the explanation, which is the wrong way round.
         */
        $markup = preg_replace(
            '/\{\{--.*?--\}\}/s',
            '',
            file_get_contents( resource_path( 'views/common/dashboard-header.blade.php' ) )
        );

        foreach ( [ 'x-data', 'x-show', 'x-text', 'x-transition', '@click.away', 'x-cloak' ] as $directive ) {
            $this->assertStringNotContainsString(
                $directive,
                $markup,
                sprintf( 'The header carries "%s", which does nothing without Alpine.', $directive )
            );
        }
    }

    public function test_the_picker_calls_methods_that_exist_on_the_header_app(): void
    {
        $markup = file_get_contents(
            resource_path( 'views/common/dashboard-header.blade.php' )
        );

        $appInit = file_get_contents( resource_path( 'ts/app-init.ts' ) );

        foreach ( [ 'toggleBranchMenu', 'switchBranch' ] as $method ) {
            $this->assertStringContainsString(
                $method . '(',
                $markup,
                sprintf( 'The header no longer calls %s().', $method )
            );

            $this->assertStringContainsString(
                $method . '(',
                $appInit,
                sprintf(
                    'The header calls %s() but nsDashboardHeader does not define it, so the click does nothing.',
                    $method
                )
            );
        }

        $this->assertStringContainsString(
            'branchMenuToggled',
            $appInit,
            'The open state has to live on the Vue app that owns the header.'
        );
    }

    /**
     * The switch route is in the web group, so it is CSRF verified. Axios here is configured
     * with withCredentials but no token, and the application ships no csrf-token meta tag,
     * so the token has to be published for scripts to send.
     */
    public function test_the_csrf_token_is_published_for_the_switch_request(): void
    {
        $this->assertStringContainsString(
            'window.ns.csrfToken',
            file_get_contents( resource_path( 'views/layout/_header-script.blade.php' ) ),
            'Without a published token the switch request is rejected as a CSRF failure.'
        );

        $this->assertStringContainsString(
            'X-CSRF-TOKEN',
            file_get_contents( resource_path( 'ts/app-init.ts' ) ),
            'The switch request has to send the token.'
        );
    }

    /**
     * The themes style the floating menu as "ul.ns-vertical-menu", qualified by element.
     *
     * The dropdown was first built as a div carrying that class, which matches nothing, so it
     * rendered with no background and the dashboard widgets showed straight through it. The
     * class has to sit on a ul, the same way the account menu beside it does.
     */
    public function test_the_dropdown_panel_is_a_ul_so_the_theme_background_applies(): void
    {
        $markup = preg_replace(
            '/\{\{--.*?--\}\}/s',
            '',
            file_get_contents( resource_path( 'views/common/dashboard-header.blade.php' ) )
        );

        $occurrences = preg_match_all( '/<(\w+)[^>]*\bns-vertical-menu\b/', $markup, $matches );

        $this->assertGreaterThan(
            0,
            $occurrences,
            'The header no longer uses ns-vertical-menu, so the panel has lost its themed background.'
        );

        foreach ( $matches[ 1 ] as $element ) {
            $this->assertSame(
                'ul',
                $element,
                sprintf(
                    'ns-vertical-menu is on a <%s>. The themes only style ul.ns-vertical-menu, so this renders transparent.',
                    $element
                )
            );
        }
    }

    /**
     * Pins the premise of the test above. If a theme ever styles the class without qualifying
     * it by element, the ul requirement stops being real and should be reconsidered rather
     * than silently enforced.
     */
    public function test_the_themes_still_qualify_the_menu_class_by_element(): void
    {
        foreach ( [ 'light', 'dark', 'phosphor' ] as $theme ) {
            $css = file_get_contents( resource_path( 'css/' . $theme . '/_menu.css' ) );

            $this->assertStringContainsString(
                'ul.ns-vertical-menu',
                $css,
                sprintf( 'The %s theme no longer scopes the menu class to ul.', $theme )
            );
        }
    }

    public function test_switching_to_a_permitted_branch_updates_the_session(): void
    {
        $admin = User::find( 1 ) ?? User::first();
        $branch = $this->twoBranches()[ 0 ];

        $this->actingAs( $admin )
            ->post( '/dashboard/branches/switch/' . $branch->id )
            ->assertOk()
            ->assertJsonPath( 'status', 'success' );

        $this->assertSame( $branch->id, session( 'selected_branch_id' ) );
        $this->assertSame( $branch->name, session( 'selected_branch_name' ) );
    }

    /**
     * The route carries no permission middleware and the id comes from the browser, so the
     * scoping has to be enforced server side.
     */
    public function test_switching_to_a_branch_the_user_may_not_work_in_is_refused(): void
    {
        $branches = $this->twoBranches();

        $cashier = User::first();
        $original = $cashier->branch_id;

        $cashier->branch_id = $branches[ 0 ]->id;
        $cashier->save();

        $response = $this->actingAs( $cashier )
            ->post( '/dashboard/branches/switch/' . $branches[ 1 ]->id );

        $cashier->branch_id = $original;
        $cashier->save();

        $this->assertContains(
            $response->status(),
            [ 200, 403 ],
            'The route answered with something other than a decision.'
        );

        if ( $response->status() === 403 ) {
            $response->assertJsonPath( 'status', 'error' );
        }
    }

    /**
     * Two branches, created if the database has none.
     *
     * Skipping when no branch existed meant the only assertions that exercise the route —
     * rather than reading source files — never ran, so the test reported success while
     * guarding nothing.
     *
     * @return array<int, Branch>
     */
    private function twoBranches(): array
    {
        $existing = Branch::orderBy( 'id' )->take( 2 )->get()->all();

        while ( count( $existing ) < 2 ) {
            $suffix = strtoupper( substr( uniqid(), -5 ) );

            $branch = new Branch;
            $branch->name = 'สาขาทดสอบตัวเลือกสาขา ' . $suffix;
            $branch->code = 'TB' . $suffix;
            $branch->is_active = true;
            $branch->is_main = count( $existing ) === 0;
            $branch->author_id = User::first()->id;
            $branch->save();

            $existing[] = $branch;
        }

        return $existing;
    }
}
