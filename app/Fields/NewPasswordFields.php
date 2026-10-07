<?php

namespace App\Fields;

use App\Classes\Hook;
use App\Services\FieldsService;
use App\Services\PasswordPolicyService;

class NewPasswordFields extends FieldsService
{
    /**
     * The unique identifier of the form
     **/
    const IDENTIFIER = 'ns.new-password';

    /**
     * Will ensure the fields are automatically
     * loaded
     **/
    const AUTOLOAD = true;

    public function get()
    {
        $fields = Hook::filter( 'ns-new-password-fields', [
            [
                'label' => __( 'New Password' ),
                'description' => app( PasswordPolicyService::class )->describe(),
                'validation' => app( PasswordPolicyService::class )->validationString(),
                'name' => 'password',
                'type' => 'password',
            ],  [
                'label' => __( 'Confirm Password' ),
                'description' => __( 'confirm your new password.' ),
                'validation' => 'same:password',
                'name' => 'password_confirm',
                'type' => 'password',
            ],
        ] );

        return $fields;
    }
}
