<?php

use App\Http\Controllers\Dashboard\ReportsController;
use Illuminate\Support\Facades\Route;

Route::get( '/reports/sales', [ ReportsController::class, 'salesReport' ] )->name( ns()->routeName( 'ns.dashboard.report.sales' ) );
Route::get( '/reports/sales-progress', [ ReportsController::class, 'salesProgress' ] )->name( ns()->routeName( 'ns.dashboard.report.sale-progress' ) );
Route::get( '/reports/low-stock', [ ReportsController::class, 'stockReport' ] )->name( ns()->routeName( 'ns.dashboard.reports-low-stock' ) ); // @todo update
Route::get( '/reports/sold-stock', [ ReportsController::class, 'soldStock' ] )->name( ns()->routeName( 'ns.dashboard.reports.sold-stock' ) );
Route::get( '/reports/stock-history', [ ReportsController::class, 'stockCombinedReport' ] )->name( ns()->routeName( 'ns.dashboard.reports.stock-history' ) );
Route::get( '/reports/profit', [ ReportsController::class, 'profit' ] )->name( ns()->routeName( 'ns.dashboard.reports.profit' ) );
Route::get( '/reports/transactions', [ ReportsController::class, 'transactionsReport' ] )->name( ns()->routeName( 'ns.dashboard.reports.transactions' ) );
Route::get( '/reports/annual-report', [ ReportsController::class, 'annualReport' ] )->name( ns()->routeName( 'ns.dashboard.reports-annual' ) ); // @todo update
Route::get( '/reports/payment-types', [ ReportsController::class, 'salesByPaymentTypes' ] )->name( ns()->routeName( 'ns.dashboard.reports.payment-types' ) );
Route::get( '/reports/customers-statement', [ ReportsController::class, 'showCustomerStatement' ] )->name( ns()->routeName( 'ns.dashboard.reports.customers-statement' ) );
Route::get( '/reports/vat-return', [ ReportsController::class, 'vatReturnReport' ] )->name( ns()->routeName( 'ns.dashboard.reports.vat-return' ) );
Route::get( '/reports/withholding-tax', [ ReportsController::class, 'withholdingTaxReport' ] )->name( ns()->routeName( 'ns.dashboard.reports.withholding-tax' ) );

/**
 * Two routes onto one view. They are separate pages because they are separate sheets in
 * the shop's filing and staff ask for them by name, but the columns and totals are
 * identical so they share a template and a service call.
 */
Route::get( '/reports/delivery-notes/cash', [ ReportsController::class, 'deliveryNotesCashReport' ] )->name( ns()->routeName( 'ns.dashboard.reports.delivery-notes-cash' ) );
Route::get( '/reports/delivery-notes/consignment', [ ReportsController::class, 'deliveryNotesConsignmentReport' ] )->name( ns()->routeName( 'ns.dashboard.reports.delivery-notes-consignment' ) );
Route::get( '/reports/receivables', [ ReportsController::class, 'receivablesReport' ] )->name( ns()->routeName( 'ns.dashboard.reports.receivables' ) );

Route::get( '/reports/daily-reconciliation', [ReportsController::class, 'dailyReconciliation'] )->name( 'ns.dashboard.reports.daily-reconciliation' );

Route::get('/business-dashboards/sales', [\App\Http\Controllers\BusinessDashboardController::class, 'sales'])->name(ns()->routeName('ns.business.sales'));
Route::get('/business-dashboards/purchase', [\App\Http\Controllers\BusinessDashboardController::class, 'purchase'])->name(ns()->routeName('ns.business.purchase'));
Route::get('/business-dashboards/inventory', [\App\Http\Controllers\BusinessDashboardController::class, 'inventory'])->name(ns()->routeName('ns.business.inventory'));
Route::get('/business-dashboards/financial', [\App\Http\Controllers\BusinessDashboardController::class, 'financial'])->name(ns()->routeName('ns.business.financial'));
Route::get('/business-dashboards/kpi', [\App\Http\Controllers\BusinessDashboardController::class, 'kpi'])->name(ns()->routeName('ns.business.kpi'));
Route::post('/business-dashboards/kpi/targets', [\App\Http\Controllers\BusinessDashboardController::class, 'saveTargets'])->name(ns()->routeName('ns.business.kpi-targets'));

Route::get('/performance/{page}', [\App\Http\Controllers\PerformanceController::class, 'index'])->whereIn('page',['metrics','goals','evaluations','scores','annual'])->name('ns.performance.index');
Route::post('/performance/actions/{action}', [\App\Http\Controllers\PerformanceController::class, 'store'])->whereIn('action',['metric','goal','evaluate','cancel','annual'])->name('ns.performance.store');

Route::get('/promotions/scheduled', [\App\Http\Controllers\ScheduledPriceController::class,'index'])->name('ns.scheduled-prices.index');
Route::get('/promotions/scheduled/products', [\App\Http\Controllers\ScheduledPriceController::class,'lookup'])->name('ns.scheduled-prices.lookup');
Route::post('/promotions/scheduled', [\App\Http\Controllers\ScheduledPriceController::class,'store'])->name('ns.scheduled-prices.store');
Route::post('/promotions/scheduled/{id}/stop', [\App\Http\Controllers\ScheduledPriceController::class,'stop'])->name('ns.scheduled-prices.stop');

Route::get('/reports/replenishment', [\App\Http\Controllers\ReplenishmentReportController::class, 'index'])->name('ns.reports.replenishment');
