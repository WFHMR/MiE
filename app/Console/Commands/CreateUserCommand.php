<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\PasswordPolicyService;
use App\Services\UsersService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateUserCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:user';

    protected $username;

    protected $email;

    protected $role;

    protected $password;

    protected $password_confirm;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Help to create users from the command line.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct( protected UsersService $usersService )
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        /**
         * We might need to throttle this command.
         */
        if ( Role::namespace( 'admin' )->users->count() > 0 ) {
            $this->line( 'Administrator Authentication' );
            $username = $this->ask( 'Provide your username' );
            $password = $this->secret( 'Provide your password' );

            if ( ! Auth::attempt( compact( 'username', 'password' ) ) ) {
                return $this->error( 'Incorrect username or password.' );
            }

            $this->info( 'You\'re authenticated' );
        }

        /**
         * perform
         *
         * @return bool
         */
        if ( $this->checkUsername() === false ) {
            return true;
        }

        /**
         * perform
         *
         * @return bool
         */
        if ( $this->checkEmail() === false ) {
            return true;
        }

        /**
         * perform
         *
         * @return bool
         */
        if ( $this->checkPassword() === false ) {
            return true;
        }

        /**
         * perform
         *
         * @return bool
         */
        if ( $this->checkPasswordConfirm() === false ) {
            return true;
        }

        /**
         * perform
         *
         * @return bool
         */
        if ( $this->checkRole() === false ) {
            return true;
        }

        if ( User::whereUsername( $this->username )->first() ) {
            return $this->error( 'The username is already in use.' );
        }

        if ( strlen( $this->password ) < 5 ) {
            return $this->error( 'The provided password is too short.' );
        }

        /**
         * Role::namespace() returns the Role itself, not a query builder,
         * so it must not be chained with a first()/firstOrFail() call.
         */
        $role = Role::namespace( $this->role );

        if ( ! $role instanceof Role ) {
            return $this->error( 'The provided role identifier is not valid.' );
        }

        $user = new User;
        $user->username = $this->username;
        $user->email = $this->email;
        $user->password = Hash::make( $this->password );
        $user->save();

        /**
         * If the request wasn't authenticated
         * then the author of this user
         * is himself.
         */
        $user->author_id = Auth::user() instanceof User ? Auth::id() : $user->id;
        $user->active = Auth::user() instanceof User ? 1 : 0;
        $user->save();

        /**
         * Roles are stored on a pivot table, and every user is expected
         * to own an attribute record.
         */
        $this->usersService->setUserRole( $user, [ $role->id ] );
        $this->usersService->createAttribute( $user );

        $this->info( 'A new account has been created' );
    }

    public function checkRole()
    {
        while ( true ) {
            $this->role = $this->anticipate( 'New Account Role. [Q] Quit', Role::get()->map( fn( $role ) => $role->namespace )->toArray() );

            if ( $this->role === 'Q' ) {
                return false;
            }

            if ( ! Role::namespace( $this->role ) instanceof Role ) {
                $this->error( 'The provided role identifier is not valid.' );
            } else {
                break;
            }
        }
    }

    public function checkPasswordConfirm()
    {
        while ( true ) {
            $this->password_confirm = $this->secret( 'New Account Password Confirmation. [Q] Quit' );

            if ( $this->password_confirm === 'Q' ) {
                return false;
            }

            if ( $this->password_confirm !== $this->password ) {
                $this->error( 'The password confirmation doesn\'t match the password.' );
            } else {
                break;
            }
        }
    }

    public function checkPassword()
    {
        while ( true ) {
            $this->password = $this->secret( 'New Account Password. [Q] Quit.' );

            if ( $this->password === 'Q' ) {
                return false;
            }

            /**
             * Held to the shop's own policy rather than to a hardcoded five, which was both
             * weaker than every form in the application and unaffected by anything the shop
             * configured. An account created from the command line could sign in to the same
             * till as one created from the users screen, so the weaker door was the real rule.
             */
            $failures = app( PasswordPolicyService::class )->failures( $this->password );

            if ( $failures !== [] ) {
                foreach ( $failures as $failure ) {
                    $this->error( $failure );
                }
            } else {
                break;
            }
        }
    }

    public function checkEmail()
    {
        while ( true ) {
            $this->email = $this->ask( 'New Account Email. [Q] Quit.' );

            $validator = Validator::make( [
                'email' => $this->email,
            ], [
                'email' => 'required|email',
            ] );

            if ( $this->email === 'Q' ) {
                return false;
            }

            if ( User::whereUsername( $this->username )->first() instanceof User ) {
                return $this->error( 'The username is already in use.' );
            }

            if ( $validator->fails() ) {
                $this->error( 'The provided email is not valid.' );
            } else {
                break;
            }
        }
    }

    public function checkUsername()
    {
        while ( true ) {
            $this->username = $this->ask( 'New Account Username. [Q] Quit.' );

            $validator = Validator::make( [
                'username' => $this->username,
            ], [
                'username' => 'required|min:5',
            ] );

            if ( $this->username === 'Q' ) {
                return false;
            }

            if ( User::whereUsername( $this->username )->first() instanceof User ) {
                return $this->error( 'The username is already in use.' );
            }

            if ( $validator->fails() ) {
                $this->error( 'The provided username is not valid.' );
            } else {
                break;
            }
        }
    }
}
