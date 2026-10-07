<?php

use App\Http\Controllers\Dashboard\ReceiptBuilderController;
use App\Http\Controllers\Dashboard\SettingsController;
use App\Http\Middleware\NsRestrictMiddleware;
use Illuminate\Support\Facades\Route;

Route::post( '/settings/receipt-builder', [ ReceiptBuilderController::class, 'save' ] )->middleware( NsRestrictMiddleware::arguments( 'manage.options' ) );

Route::get( '/settings/{identifier}', [ SettingsController::class, 'getSettingsForm' ] )->middleware( NsRestrictMiddleware::arguments( 'manage.options' ) );
Route::post( '/settings/{identifier}', [ SettingsController::class, 'saveSettingsForm' ] )->middleware( NsRestrictMiddleware::arguments( 'manage.options' ) );
