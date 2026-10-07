@component('mail::message')
@if ( ns()->option->get( 'ns_notifications_registrations_user_email_body' ) )
    {!! ns()->option->get( 'ns_notifications_registrations_user_email_body' ) !!}
@else
    # {{ __( 'Your Account Has Been Created' ) }}

    {{ 
        sprintf(
            __( 'The account you have created for __%s__, has been successfully created. You can now login user your username (__%s__) and the password you have defined.' ),
            ns()->option->get( 'ns_store_name' ),
            $user->username
        )
    }}
@endif

@component('mail::button', ['url' => route( 'ns.login' ) ])
{{ __( 'Login' ) }}
@endcomponent

@endcomponent
