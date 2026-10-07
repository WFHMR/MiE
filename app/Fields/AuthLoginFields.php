<?php

namespace App\Fields;

use App\Classes\Form;
use App\Classes\FormInput;
use App\Classes\Hook;
use App\Services\FieldsService;

class AuthLoginFields extends FieldsService
{
    /**
     * The unique identifier of the form
     **/
    const IDENTIFIER = 'ns.login';

    /**
     * Will ensure the fields are automatically loaded
     **/
    const AUTOLOAD = true;

    public function get()
    {
        $fields = Hook::filter( 'ns-login-fields',
            Form::fields(
                FormInput::text(
                    label: __( 'Username' ),
                    description: __( 'Provide your username.' ),
                    validation: 'required|min:5',
                    name: 'username',
                ),
                FormInput::password(
                    label: __( 'Password' ),
                    description: __( 'Provide your password.' ),

                    /**
                     * Deliberately no length rule, and the password policy is deliberately not
                     * applied here. This is the box somebody types an existing password into.
                     * Holding it to a minimum length refuses the attempt before it is checked,
                     * so the day the shop raises the minimum every member of staff whose
                     * password is shorter than the new figure would be unable to sign in at all —
                     * including to go and change it. The policy belongs where a password is
                     * being set, not where one is being presented.
                     */
                    validation: 'required',
                    name: 'password',
                )
            )
        );

        return $fields;
    }
}
