<?php

/**
 * Branch switching route.
 *
 * This stays on the dashboard prefix because the branch picker in
 * common/dashboard-header.blade.php posts to url('dashboard/branches/switch')/{id} using the
 * dashboard session cookie and a CSRF token. The api group authenticates with auth:sanctum and
 * carries no session, so moving this there would sign the request out.
 *
 * The JSON-only getCurrentBranch endpoint lives in routes/api/branches.php instead.
 */

use App\Http\Controllers\Dashboard\BranchController;
use Illuminate\Support\Facades\Route;

Route::post( '/branches/switch/{id}', [ BranchController::class, 'switchBranch' ] )
    ->name( 'ns.dashboard.switch-branch' );

/**
 * The list, create and edit pages BranchCrud::getLinks() already declares.
 *
 * Declared before the {id} routes of no other group, but after the switch route above, so the
 * literal "switch" segment cannot be captured as a branch id.
 */
Route::get( '/branches', [ BranchController::class, 'listBranches' ] )
    ->name( 'ns.dashboard.branches' );

Route::get( '/branches/create', [ BranchController::class, 'createBranch' ] )
    ->name( 'ns.dashboard.branches-create' );

Route::get( '/branches/edit/{branch}', [ BranchController::class, 'editBranch' ] )
    ->name( 'ns.dashboard.branches-edit' );
