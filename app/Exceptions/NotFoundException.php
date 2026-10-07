<?php

namespace App\Exceptions;

use App\Services\Helper;
use Exception;

class NotFoundException extends Exception
{
    public function __construct( $message = null )
    {
        $this->message = $message ?: __( 'The resource of the page you tried to access is not available or might have been deleted.' );
    }

    public function getStatusCode()
    {
        return 404;
    }

    public function render( $request )
    {
        if ( ! $request->expectsJson() ) {
            /**
             * The status code was missing here, so every "not found" page answered 200 while telling
             * the reader the resource does not exist. The JSON branch below has always answered 404,
             * which is how the two disagreed — a missing barcode image, for one, came back as a
             * successful response carrying an error page, and an <img> pointed at it shows a broken
             * picture rather than nothing. NotAllowedException already passes its code through.
             */
            return response()->view( 'pages.errors.not-allowed', [
                'title' => __( 'Not Found Exception' ),
                'message' => $this->getMessage(),
                'back' => Helper::getValidPreviousUrl( $request ),
            ], $this->getStatusCode() );
        }

        return response()->json( [
            'status' => 'error',
            'message' => $this->getMessage(),
        ], 404 );
    }
}
