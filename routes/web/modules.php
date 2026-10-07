<?php

use App\Http\Controllers\Dashboard\ModulesController;
use Illuminate\Support\Facades\Route;

Route::get( '/modules', [ ModulesController::class, 'listModules' ] )->name( 'ns.dashboard.modules-list' );
Route::get( '/modules/upload', [ ModulesController::class, 'showUploadModule' ] )->name( 'ns.dashboard.modules-upload' );
Route::get( '/modules/download/{identifier}', [ ModulesController::class, 'downloadModule' ] )->name( 'ns.dashboard.modules-download' );
/**
 * modules/migrate/{namespace} was declared here pointing at ModulesController::migrateModule, which does
 * not exist. Nothing linked to it, so it was reachable only by typing the url, and it answered 500 when
 * anyone did. Module migrations run through the database update page, which UpdateController serves.
 */
Route::get( '/modules/marketplace', [ ModulesController::class, 'showMarketplace' ] )->name( 'ns.dashboard.modules-marketplace' );
