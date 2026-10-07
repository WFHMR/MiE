<?php

namespace App\Http\Requests;

use App\Exceptions\NotAllowedException;
use App\Services\Options;
use App\Services\PasswordPolicyService;
use Illuminate\Foundation\Http\FormRequest;

class SignUpRequest extends FormRequest
{
    public $redirectRoute = 'ns.register';

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        $options = app()->make( Options::class );

        if ( $options->get( 'ns_registration_enabled' ) !== 'yes' ) {
            throw new NotAllowedException( __( 'Unable to register. The registration is closed.' ) );
        }

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'username' => 'required|min:3',
            'email' => 'email',

            /**
             * This used to be "required" and nothing else, so the registration form was the one
             * door into the application that accepted a single character password — the "min:6"
             * on the form field was advice the browser could be told to ignore, and the server
             * never checked it.
             */
            'password' => app( PasswordPolicyService::class )->validationString(),
            'password_confirm' => 'same:password',
        ];
    }
}
