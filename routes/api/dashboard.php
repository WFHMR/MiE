<?php

use App\Http\Controllers\DashboardController;
use App\Http\Middleware\NsRestrictMiddleware;
use Illuminate\Support\Facades\Route;

Route::middleware( NsRestrictMiddleware::arguments( 'read.dashboard' ) )->group( function () {
    Route::get( 'dashboard/day', [ DashboardController::class, 'getCards' ] );

    /**
     * Takings for a named period. The takings widget used to ask dashboard/day for all three of its tabs
     * and read fields off the reply that do not exist, so two of the tabs showed the same all-time figure
     * and the counts beside them were always zero.
     */
    Route::get( 'dashboard/sales-summary', [ DashboardController::class, 'getSalesSummary' ] );
    Route::get( 'dashboard/best-customers', [ DashboardController::class, 'getBestCustomers' ] );
    Route::get( 'dashboard/best-cashiers', [ DashboardController::class, 'getBestCashiers' ] );
    Route::get( 'dashboard/recent-orders', [ DashboardController::class, 'getRecentsOrders' ] );
    Route::get( 'dashboard/branches-revenue', [ DashboardController::class, 'getBranchesRevenue' ] );
    Route::get( 'dashboard/weeks', [ DashboardController::class, 'getWeekReports' ] );
} );
