<?php

use App\Http\Controllers\UpdateController;
use App\Http\Middleware\NsRestrictMiddleware;
use Illuminate\Support\Facades\Route;

Route::post( 'update', [ UpdateController::class, 'runMigration' ] )
    ->middleware( [
        'auth:sanctum',
        NsRestrictMiddleware::arguments( 'update.core' ),
    ] );
