<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateUserCommandTest extends TestCase
{
    /**
     * The command authenticates an administrator before creating
     * an account, so a known password is required.
     */
    private function prepareAdministrator( string $password ): User
    {
        $administrator = Role::namespace( 'admin' )->users->first();

        $this->assertInstanceOf( User::class, $administrator, 'No administrator is available on the test database.' );

        $administrator->password = Hash::make( $password );
        $administrator->active = 1;
        $administrator->save();

        return $administrator;
    }

    public function test_make_user_creates_an_account_with_the_requested_role(): void
    {
        $administratorPassword = 'administrator-password';
        $administrator = $this->prepareAdministrator( $administratorPassword );

        $username = 'command-' . Str::random( 8 );
        $email = $username . '@example.test';
        $password = 'created-password';
        $role = 'nexopos.store.cashier';

        $this->artisan( 'make:user' )
            ->expectsQuestion( 'Provide your username', $administrator->username )
            ->expectsQuestion( 'Provide your password', $administratorPassword )
            ->expectsQuestion( 'New Account Username. [Q] Quit.', $username )
            ->expectsQuestion( 'New Account Email. [Q] Quit.', $email )
            ->expectsQuestion( 'New Account Password. [Q] Quit.', $password )
            ->expectsQuestion( 'New Account Password Confirmation. [Q] Quit', $password )
            ->expectsQuestion( 'New Account Role. [Q] Quit', $role )
            ->expectsOutput( 'A new account has been created' )
            ->assertSuccessful();

        $user = User::where( 'username', $username )->first();

        $this->assertInstanceOf( User::class, $user, 'The command did not create the account.' );
        $this->assertSame(
            [ $role ],
            $user->roles->map( fn( Role $assigned ) => $assigned->namespace )->toArray(),
            'The requested role was not the one assigned.'
        );
        $this->assertTrue(
            $user->attribute()->count() > 0,
            'The created account is missing its attribute record.'
        );
        $this->assertTrue(
            Hash::check( $password, $user->password ),
            'The password was not stored correctly.'
        );
    }

    public function test_make_user_rejects_an_unknown_role(): void
    {
        $administratorPassword = 'administrator-password';
        $administrator = $this->prepareAdministrator( $administratorPassword );

        $username = 'command-' . Str::random( 8 );

        $this->artisan( 'make:user' )
            ->expectsQuestion( 'Provide your username', $administrator->username )
            ->expectsQuestion( 'Provide your password', $administratorPassword )
            ->expectsQuestion( 'New Account Username. [Q] Quit.', $username )
            ->expectsQuestion( 'New Account Email. [Q] Quit.', $username . '@example.test' )
            ->expectsQuestion( 'New Account Password. [Q] Quit.', 'created-password' )
            ->expectsQuestion( 'New Account Password Confirmation. [Q] Quit', 'created-password' )
            ->expectsQuestion( 'New Account Role. [Q] Quit', 'not-a-real-role' )
            ->expectsOutput( 'The provided role identifier is not valid.' )
            ->expectsQuestion( 'New Account Role. [Q] Quit', 'Q' )
            /**
             * Quitting any prompt makes the command return early with a
             * non-zero status, which is how every other prompt behaves too.
             */
            ->assertFailed();

        $this->assertNull(
            User::where( 'username', $username )->first(),
            'No account should be created when the role selection is abandoned.'
        );
    }
}
