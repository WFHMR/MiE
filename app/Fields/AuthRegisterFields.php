<?php

namespace App\Fields;

use App\Classes\FormInput;
use App\Classes\Hook;
use App\Services\FieldsService;
use App\Services\PasswordPolicyService;

class AuthRegisterFields extends FieldsService
{
    /**
     * The unique identifier of the form
     **/
    const IDENTIFIER = 'ns.register';

    /**
     * Will ensure the fields are automatically loaded
     **/
    const AUTOLOAD = true;

    public function get()
    {
        $fields = Hook::filter( 'ns-register-fields', [
            FormInput::text(
                label: __( 'Username' ),
                description: __( 'Provide your username.' ),
                validation: 'required|min:5',
                name: 'username',
            ),
            FormInput::text(
                label: __( 'Email' ),
                description: __( 'Provide your email.' ),
                validation: 'required|email',
                name: 'email',
            ),
            FormInput::password(
                label: __( 'Password' ),
                description: app( PasswordPolicyService::class )->describe(),
                validation: app( PasswordPolicyService::class )->validationString(),
                name: 'password',
            ),
            FormInput::password(
                label: __( 'Password Confirm' ),
                description: __( 'Should be the same as the password.' ),
                validation: app( PasswordPolicyService::class )->validationString() . '|same:password',
                name: 'password_confirm',
            ),
        ] );

        return $fields;
    }
}
