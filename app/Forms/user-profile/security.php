<?php

use App\Services\PasswordPolicyService;

$policy = app()->make( PasswordPolicyService::class );

return [
    'label' => __( 'Security' ),
    'fields' => [
        [
            'label' => __( 'Old Password' ),
            'name' => 'old_password',
            'type' => 'password',
            'description' => __( 'Provide the old password.' ),
        ], [
            'label' => __( 'Password' ),
            'name' => 'password',
            'type' => 'password',

            /**
             * The rule and the help text both come from the shop's password policy, so the box
             * cannot ask for one thing and refuse another.
             */
            'description' => $policy->describe(),
            'validation' => $policy->validationString( optional: true ),
        ], [
            'label' => __( 'Password Confirmation' ),
            'name' => 'password_confirm',
            'type' => 'password',
            'description' => __( 'Change your password with a better stronger password.' ),
            'validation' => 'same:security.password',
        ],
    ],
];
