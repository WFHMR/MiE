<?php

use App\Http\Controllers\Dashboard\OrdersController;
use App\Http\Middleware\NsRestrictMiddleware;
use Illuminate\Support\Facades\Route;

Route::get( '/orders', [ OrdersController::class, 'listOrders' ] )->name( ns()->routeName( 'ns.dashboard.orders' ) );
Route::get( '/orders/instalments', [ OrdersController::class, 'listInstalments' ] )->name( ns()->routeName( 'ns.dashboard.orders-instalments' ) );
Route::get( '/orders/payments-types', [ OrdersController::class, 'listPaymentsTypes' ] )->name( ns()->routeName( 'ns.dashboard.orders-payments-types' ) );
Route::get( '/orders/payments-types/create', [ OrdersController::class, 'createPaymentType' ] )->name( ns()->routeName( 'ns.dashboard.orders-create-types' ) );
Route::get( '/orders/payments-types/edit/{paymentType}', [ OrdersController::class, 'updatePaymentType' ] )->name( ns()->routeName( 'ns.dashboard.orders-update-types' ) );
Route::get('/orders/temporary-delivery', [OrdersController::class, 'temporaryDelivery'])->name('ns.orders.temporary-delivery');
Route::get( '/orders/invoice/{order}', [ OrdersController::class, 'orderInvoice' ] )->name( ns()->routeName( 'ns.dashboard.orders-invoice' ) );
Route::get( '/orders/receipt/{order}', [ OrdersController::class, 'orderReceipt' ] )->name( ns()->routeName( 'ns.dashboard.orders-receipt' ) );
Route::get( '/orders/refund-receipt/{refund}', [ OrdersController::class, 'orderRefundReceipt' ] )->name( ns()->routeName( 'ns.dashboard.orders-refund-receipt' ) );
Route::get( '/orders/payment-receipt/{orderPayment}', [ OrdersController::class, 'getOrderPaymentReceipt' ] )->name( ns()->routeName( 'ns.dashboard.orders-payment-receipt' ) );

/**
 * Restricted explicitly. The other document routes in this file inherit only the
 * authentication middleware, so any signed-in user can pull any receipt by id. A tax
 * invoice carries the shop's registration details and the buyer's, so it is gated.
 */
Route::get( '/orders/tax-invoice/{taxInvoice}', [ OrdersController::class, 'showTaxInvoice' ] )
    ->middleware( NsRestrictMiddleware::arguments( 'nexopos.read.tax-invoices' ) )
    ->name( ns()->routeName( 'ns.dashboard.orders-tax-invoice' ) );

/**
 * Gated for the same reason as the tax invoice: a billing note lists a named customer's
 * outstanding deliveries and what they owe on each, which is not something any signed-in
 * user should be able to pull by guessing an id.
 */
Route::get( '/orders/billing-note/{billingNote}', [ OrdersController::class, 'showBillingNote' ] )
    ->middleware( NsRestrictMiddleware::arguments( 'nexopos.read.billing-notes' ) )
    ->name( ns()->routeName( 'ns.dashboard.orders-billing-note' ) );

/**
 * Declared before the {billingNote} route above would be a mistake in the other direction:
 * "billing-notes" is a distinct path segment from "billing-note", so the two cannot collide.
 */
Route::get( '/orders/billing-notes/create', [ OrdersController::class, 'createBillingNote' ] )
    ->middleware( NsRestrictMiddleware::arguments( 'nexopos.create.billing-notes' ) )
    ->name( ns()->routeName( 'ns.dashboard.orders-billing-notes-create' ) );

/**
 * Declared after /create so the literal segment wins. Laravel matches in registration order
 * and this route has no parameter, but keeping the order explicit means adding a
 * /billing-notes/{note} route later cannot silently swallow /create.
 */
Route::get( '/orders/billing-notes', [ OrdersController::class, 'listBillingNotes' ] )
    ->middleware( NsRestrictMiddleware::arguments( 'nexopos.read.billing-notes' ) )
    ->name( ns()->routeName( 'ns.dashboard.orders-billing-notes' ) );
Route::get( '/pos', [ OrdersController::class, 'showPOS' ] )->name( ns()->routeName( 'ns.dashboard.pos' ) );

// Separate sales documents. Every action enforces branch and order permissions.
Route::get('/sales/quotations', [\App\Http\Controllers\SalesWorkflowController::class, 'quotations'])->name(ns()->routeName('ns.sales.quotations'));
Route::get('/sales/quotations/create', [\App\Http\Controllers\SalesWorkflowController::class, 'form'])->name(ns()->routeName('ns.sales.quote-create'));
Route::post('/sales/quotations', [\App\Http\Controllers\SalesWorkflowController::class, 'save'])->name(ns()->routeName('ns.sales.quote-save'));
Route::get('/sales/lookup', [\App\Http\Controllers\SalesWorkflowController::class, 'lookup'])->name(ns()->routeName('ns.sales.lookup'));
Route::get('/sales/quotations/{id}/edit', [\App\Http\Controllers\SalesWorkflowController::class, 'form'])->name(ns()->routeName('ns.sales.quote-edit'));
Route::post('/sales/quotations/{id}/edit', [\App\Http\Controllers\SalesWorkflowController::class, 'save'])->name(ns()->routeName('ns.sales.quote-update'));
Route::get('/sales/quotations/{id}', [\App\Http\Controllers\SalesWorkflowController::class, 'quote'])->name(ns()->routeName('ns.sales.quote'));
Route::post('/sales/quotations/{id}/action', [\App\Http\Controllers\SalesWorkflowController::class, 'quoteAction'])->name(ns()->routeName('ns.sales.quote-action'));
Route::post('/sales/quotations/{id}/accept', [\App\Http\Controllers\SalesWorkflowController::class, 'accept'])->name(ns()->routeName('ns.sales.accept'));
Route::get('/sales/orders', [\App\Http\Controllers\SalesWorkflowController::class, 'orders'])->name(ns()->routeName('ns.sales.orders'));
Route::get('/sales/orders/{id}', [\App\Http\Controllers\SalesWorkflowController::class, 'order'])->name(ns()->routeName('ns.sales.order'));
Route::post('/sales/orders/{id}/action', [\App\Http\Controllers\SalesWorkflowController::class, 'orderAction'])->name(ns()->routeName('ns.sales.order-action'));
Route::get('/sales/deliveries', [\App\Http\Controllers\SalesWorkflowController::class, 'deliveries'])->name(ns()->routeName('ns.sales.deliveries'));
Route::get('/sales/deliveries/{id}', [\App\Http\Controllers\SalesWorkflowController::class, 'delivery'])->name(ns()->routeName('ns.sales.delivery'));
Route::post('/sales/deliveries/{id}/action', [\App\Http\Controllers\SalesWorkflowController::class, 'deliveryAction'])->name(ns()->routeName('ns.sales.delivery-action'));

Route::get('/sales/reservations', [\App\Http\Controllers\SalesWorkflowController::class, 'reservations'])->name('ns.sales.reservations');
