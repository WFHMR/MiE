<?php

/**
 * Branch selection API endpoint.
 *
 * getCurrentBranch answers JSON but was mounted under the dashboard prefix, where every other route
 * returns a rendered page. The suite that walks every dashboard route looking for a page found JSON
 * here and failed. Nothing in the application referenced this path, so moving it costs nothing.
 *
 * switchBranch deliberately stays on the dashboard prefix in routes/web/branches.php: the branch
 * picker in common/dashboard-header.blade.php posts to dashboard/branches/switch with the session
 * cookie and a CSRF token, and this group authenticates with auth:sanctum and carries no session.
 */

use App\Http\Controllers\Dashboard\BranchController;
use Illuminate\Support\Facades\Route;

Route::get( '/branches/current', [ BranchController::class, 'getCurrentBranch' ] )
    ->name( 'ns-api.branches.current' );
