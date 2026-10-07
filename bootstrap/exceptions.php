<?php

/**
 * if the debugging is truthy, we'll
 * then use the default behavior.
 */

use App\Services\Helper;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * If the App Debug is enabled, we should
 * let the app handle the exceptions itself.
 */
if ( env( 'APP_DEBUG' ) ) {
    return;
}

/**
 * @var Exceptions $exceptions
 */
$exceptions->render( function ( AuthenticationException $exception, Request $request ) {
    if ( $request->expectsJson() ) {
        return response()->json( [ 'message' => $exception->getMessage() ], 401 );
    } else {
        return redirect()->guest( ns()->route( 'ns.login' ) );
    }
} );

/**
 * A list of the inputs that are never flashed for validation exceptions.
 */
$exceptions->dontFlash( [
    'password',
    'password_confirmation',
    'password_confirm',
] );

$exceptions->render( function ( NotFoundHttpException $exception, Request $request ) {
    $title = __( 'Page Not Found' );
    $back = Helper::getValidPreviousUrl( $request );
    $message = $exception->getMessage() ?: __( 'The page you are looking for could not be found.' );

    if ( $request->expectsJson() ) {
        return response()->json( [ 'message' => $message ], 404 );
    } else {
        return response()->view( 'pages.errors.not-found-exception', compact( 'message', 'title', 'back' ), 404 );
    }
} );

$exceptions->render( function ( Throwable $exception, Request $request ) {
    if ( $exception instanceof ValidationException || $exception instanceof HttpResponseException || $exception instanceof HttpExceptionInterface ) {
        return null;
    }

    $title = __( 'An Error Occurred' );
    $back = Helper::getValidPreviousUrl( $request );
    $message = $title;

    if ( $request->expectsJson() ) {
        return response()->json( [
            'status' => 'error',
            'message' => $message,
            'previous' => $back,
        ], 500 );
    } else {
        return response()->view( 'pages.errors.exception', compact( 'message', 'title', 'back' ), 500 );
    }
} );
