<?php

use App\Http\Controllers\Dashboard\AuditTrailController;
use Illuminate\Support\Facades\Route;

/**
 * ความปลอดภัย — the screens the shop reads to answer "who did this" and to set the rules
 * everybody's password is held to.
 *
 * The password policy needs no route of its own: settings pages are served by the generic
 * /dashboard/settings/{settings} route and discovered from app/Settings, so PasswordSettings is
 * reachable at /dashboard/settings/password.
 */
Route::get( '/audit-trail', [ AuditTrailController::class, 'index' ] )->name( 'ns.dashboard.audit-trail' );
