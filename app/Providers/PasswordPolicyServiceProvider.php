<?php

namespace App\Providers;

use App\Services\PasswordPolicyService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;

/**
 * Makes the shop's password policy available to the validator as "password_policy".
 *
 * A rule object would have been the modern way to write this, but the password fields in this
 * application declare their rules as strings — app/Fields/*.php and app/Forms/* ship them to the
 * browser, and the Vue form builder reads the same string the server validates with. A string rule
 * is therefore the only shape that can be applied in all eleven places at once without rewriting
 * the form layer, and the client side validator ignores rules it does not recognise, so the string
 * is harmless there while the server enforces it.
 *
 * The message is the policy itself rather than "the password is invalid", because somebody being
 * refused needs to be told the rule they are being held to.
 */
class PasswordPolicyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Validator::extend( 'password_policy', function ( $attribute, $value, $parameters, $validator ): bool {
            /**
             * A blank value is left to "required" and "sometimes" to judge. Without this, clearing
             * the password box on the edit-user form — which means "keep the current password" —
             * would be refused for being too short.
             */
            if ( $value === null || $value === '' ) {
                return true;
            }

            return app( PasswordPolicyService::class )->passes( (string) $value );
        } );

        Validator::replacer( 'password_policy', function ( $message, $attribute, $rule, $parameters ): string {
            return app( PasswordPolicyService::class )->describe();
        } );
    }
}
