<?php

namespace Tests\Feature;

use App\Models\Branch;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * The branch picker used to build a JavaScript call containing the branch name, so a name with an
 * apostrophe ended the string early and broke the handler. That was fixed by escaping the name
 * with Js::from.
 *
 * The picker has since been rebuilt on the Vue app that owns the header, and it now passes only
 * the branch id. There is no branch name inside a JavaScript literal any more, which removes the
 * class of bug rather than escaping around it, so these assertions pin the absence of the
 * interpolation instead of the correctness of the escaping.
 *
 * The name still has to reach the page as text for the user to read, and Blade escapes it there.
 *
 * The test creates and removes its own branch so the shared fixture is left untouched. It acts as
 * a user on the web guard, like TestOtherGetRoutes does, because the dashboard layout reads
 * Auth::viaRemember() which the token guard does not implement.
 */
class BranchSelectorRenderTest extends TestCase
{
    use WithAuthentication;

    public function test_the_branch_name_is_never_interpolated_into_javascript(): void
    {
        $user = $this->attemptGetAnyUserFromRole();

        $branch = new Branch;
        $branch->name = "O'Brien \"หลัก\"";
        $branch->code = 'TSTQ';
        $branch->is_active = true;
        $branch->is_main = false;
        $branch->author_id = $user->id;
        $branch->save();

        try {
            $response = $this->actingAs( $user )->json( 'GET', 'dashboard' );
            $response->assertStatus( 200 );

            $content = $response->getContent();

            /**
             * The handler takes the id and nothing else. The session is the only thing that
             * decides the name, so there is no second argument to get wrong.
             */
            $this->assertStringContainsString(
                'switchBranch(' . $branch->id . ')',
                $content,
                'The picker no longer calls switchBranch with just the branch id.'
            );

            /**
             * The old shape, and the specific breakage it caused. Either appearing again means
             * a branch name is back inside a JavaScript string.
             */
            $this->assertStringNotContainsString(
                'switchBranch(' . $branch->id . ', ',
                $content,
                'A second argument is being passed again, which puts the name back into JavaScript.'
            );

            $this->assertStringNotContainsString(
                'switchBranch(' . $branch->id . ", 'O'Brien",
                $content
            );

            /**
             * The name still has to be readable on the page, escaped by Blade rather than by a
             * JavaScript escaper.
             */
            $this->assertStringContainsString(
                'O&#039;Brien',
                $content,
                'The branch name is not rendered as escaped HTML text.'
            );

            /**
             * The English literals that sat in this template are gone.
             */
            $this->assertStringNotContainsString( 'Main Branch', $content );
            $this->assertStringNotContainsString( 'Failed to switch branch', $content );
        } finally {
            $branch->delete();
        }
    }
}
