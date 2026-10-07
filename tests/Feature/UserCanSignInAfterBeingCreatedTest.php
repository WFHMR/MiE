<?php

namespace Tests\Feature;

use App\Crud\UserCrud;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * A member of staff who has just been created must be able to sign in.
 *
 * The create form offered an "Active" switch that started at No, and an account saved that way
 * cannot be used: AuthController signs the user back out on the next request. Nothing on screen
 * said so, and the activation mail that would normally rescue it cannot be delivered from a shop's
 * own server, so the only remedy was for an administrator to guess that the switch was the cause.
 * On the live system two accounts were created this way and neither could log in.
 *
 * The existing user tests all pass 'active' => 1 by hand, which is precisely why they never saw
 * this. These assert the default the form arrives with, and the consequence of getting it wrong.
 */
class UserCanSignInAfterBeingCreatedTest extends TestCase
{
    use WithAuthentication;

    /** @var list<int> */
    private array $createdUserIds = [];

    protected function tearDown(): void
    {
        /**
         * Relations and attributes go first. A role relation left pointing at a deleted user makes
         * "find any admin" resolve to nothing, which fails tests far away from this file.
         */
        if ( $this->createdUserIds !== [] ) {
            DB::table( 'nexopos_users_roles_relations' )->whereIn( 'user_id', $this->createdUserIds )->delete();
            DB::table( 'nexopos_users_attributes' )->whereIn( 'user_id', $this->createdUserIds )->delete();
            DB::table( 'nexopos_users' )->whereIn( 'id', $this->createdUserIds )->delete();
        }

        $this->createdUserIds = [];

        parent::tearDown();
    }

    public function test_the_create_form_offers_a_user_who_can_sign_in(): void
    {
        $field = $this->activeField( app()->make( UserCrud::class )->getForm() );

        $this->assertSame(
            1,
            $field[ 'value' ],
            'The new-user form starts with Active set to No. An account saved with that default'
                . ' cannot sign in, and no mail can be delivered to activate it, so creating a'
                . ' member of staff produces someone locked out with nothing explaining why.'
        );
    }

    public function test_the_edit_form_still_shows_the_users_own_state(): void
    {
        $suspended = $this->makeUser( active: false );
        $allowed = $this->makeUser( active: true );

        $crud = app()->make( UserCrud::class );

        $this->assertSame(
            0,
            $this->activeField( $crud->getForm( $suspended ) )[ 'value' ],
            'Editing a suspended user shows Active as Yes, so saving anything else on the form'
                . ' would quietly let them back in.'
        );

        $this->assertSame(
            1,
            $this->activeField( $crud->getForm( $allowed ) )[ 'value' ],
            'Editing an active user shows Active as No, so saving anything else on the form would'
                . ' quietly lock them out.'
        );
    }

    /**
     * The reason the default matters, stated as a test rather than left as a comment.
     */
    public function test_an_inactive_account_is_refused_at_sign_in(): void
    {
        $password = Str::random( 24 );
        $user = $this->makeUser( active: false, password: $password );

        $response = $this->post( 'auth/sign-in', [
            'username' => $user->username,
            'password' => $password,
        ] );

        /** An inactive account must not be left holding a session. */
        $this->assertGuest( 'web' );

        $this->assertNotSame(
            200,
            $response->getStatusCode(),
            'Signing in with an inactive account looked successful.'
        );
    }

    public function test_an_active_account_is_accepted_at_sign_in(): void
    {
        $password = Str::random( 24 );
        $user = $this->makeUser( active: true, password: $password );

        $this->post( 'auth/sign-in', [
            'username' => $user->username,
            'password' => $password,
        ] );

        /**
         * A freshly created active account must be able to sign in. This is the fault the whole
         * file exists for, seen from the other side.
         */
        $this->assertAuthenticatedAs( $user, 'web' );
    }

    /**
     * @param  array<int, mixed>    $form
     * @return array<string, mixed>
     */
    private function activeField( array $form ): array
    {
        foreach ( $form[ 'tabs' ] as $tab ) {
            foreach ( $tab[ 'fields' ] ?? [] as $field ) {
                if ( ( $field[ 'name' ] ?? '' ) === 'active' ) {
                    return $field;
                }
            }
        }

        $this->fail( 'The user form has no "active" field, so access can no longer be granted or withheld.' );
    }

    private function makeUser( bool $active, ?string $password = null ): User
    {
        $user = new User;
        $user->username = 'signin-' . Str::lower( Str::random( 10 ) );
        $user->email = $user->username . '@example.test';
        $user->password = Hash::make( $password ?? Str::random( 24 ) );
        $user->active = $active;
        $user->author_id = 1;
        $user->save();

        /**
         * Deliberately given no role.
         *
         * Signing in only checks the password and the active flag, so a role adds nothing here.
         * It does add a hazard: other tests in this suite reach for "any user holding the admin
         * role", and an extra admin with a random password made those tests act as an account they
         * knew nothing about. Worse, deleting the user afterwards left the role relation pointing
         * at a row that no longer existed, which is how a user test managed to break the product
         * import tests further down the same run.
         */
        $this->createdUserIds[] = (int) $user->id;

        return $user;
    }
}
