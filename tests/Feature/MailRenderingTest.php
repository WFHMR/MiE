<?php

namespace Tests\Feature;

use App\Mail\ActivateYourAccountMail;
use App\Mail\PasswordRecoveredMail;
use App\Mail\ResetPasswordMail;
use App\Mail\UserRegisteredMail;
use App\Mail\WelcomeMail;
use App\Models\User;
use Tests\TestCase;

class MailRenderingTest extends TestCase
{
    /**
     * The welcome template used to read from an undefined $options variable, so
     * the mail threw while rendering and never reached the new user.
     */
    public function test_welcome_mail_renders(): void
    {
        $user = User::first();

        $this->assertInstanceOf( User::class, $user, 'No user is available on the test database.' );

        $rendered = ( new WelcomeMail( $user ) )->render();

        $this->assertStringContainsString( $user->username, $rendered );
    }

    public function test_activation_mail_renders(): void
    {
        $user = User::first();

        $rendered = ( new ActivateYourAccountMail( $user ) )->render();

        $this->assertNotEmpty( $rendered );
    }

    public function test_user_registered_mail_renders(): void
    {
        $administrator = User::first();
        $user = User::skip( 1 )->first() ?? $administrator;

        $rendered = ( new UserRegisteredMail( $administrator, $user ) )->render();

        $this->assertNotEmpty( $rendered );
    }

    public function test_password_recovered_mail_renders(): void
    {
        $user = User::first();

        $rendered = ( new PasswordRecoveredMail( $user ) )->render();

        $this->assertNotEmpty( $rendered );
    }

    public function test_reset_password_mail_renders(): void
    {
        $user = User::first();

        $rendered = ( new ResetPasswordMail( $user ) )->render();

        $this->assertNotEmpty( $rendered );
    }
}
