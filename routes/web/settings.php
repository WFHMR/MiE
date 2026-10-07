<?php

use App\Http\Controllers\Dashboard\ReceiptBuilderController;
use App\Http\Controllers\Dashboard\SettingsController;
use App\Http\Middleware\NsRestrictMiddleware;
use Illuminate\Support\Facades\Route;

Route::get( '/settings/receipt-builder', [ ReceiptBuilderController::class, 'index' ] )
    ->middleware( NsRestrictMiddleware::arguments( 'manage.options' ) )
    ->name( ns()->routeName( 'ns.dashboard.settings.receipt-builder' ) );

Route::get( '/settings/{settings}', [ SettingsController::class, 'getSettings' ] )
    ->middleware( NsRestrictMiddleware::arguments( 'manage.options' ) )
    ->name( ns()->routeName( 'ns.dashboard.settings' ) );
