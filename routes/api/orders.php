<?php

use App\Http\Controllers\Dashboard\OrdersController;
use App\Http\Middleware\NsRestrictMiddleware;
use Illuminate\Support\Facades\Route;

Route::middleware( NsRestrictMiddleware::arguments( 'nexopos.read.orders' ) )->group( function () {
    Route::get( 'orders/{id?}', [ OrdersController::class, 'getOrders' ] )->where( 'id', '[0-9]+' );
    Route::get( 'orders/payments', [ OrdersController::class, 'getSupportedPayments' ] );
    Route::get( 'orders/{id}/pos', [ OrdersController::class, 'getPosOrder' ] )->where( 'id', '[0-9]+' );
    Route::get( 'orders/{id}/products', [ OrdersController::class, 'getOrderProducts' ] )->where( 'id', '[0-9]+' );
    Route::get( 'orders/{order}/products/refunded', [ OrdersController::class, 'getOrderProductsRefunded' ] )->where( 'order', '[0-9]+' );
    Route::get( 'orders/{order}/refunds', [ OrdersController::class, 'getOrderRefunds' ] )->where( 'order', '[0-9]+' );
    Route::get( 'orders/{id}/payments', [ OrdersController::class, 'getOrderPayments' ] )->where( 'id', '[0-9]+' );
} );
Route::get( 'orders/{order}/instalments', [ OrdersController::class, 'getOrderInstalments' ] )->where( 'order', '[0-9]+' )->middleware( NsRestrictMiddleware::arguments( 'nexopos.read.orders-instalments' ) );
Route::get( 'orders/{order}/print/{doc?}', [ OrdersController::class, 'printOrder' ] )->where( 'order', '[0-9]+' )->middleware( NsRestrictMiddleware::arguments( 'nexopos.read.orders' ) );
Route::post( 'orders/{order}/instalments/{instalment}/pay', [ OrdersController::class, 'payInstalment' ] )->where( 'order', '[0-9]+' )->middleware( NsRestrictMiddleware::arguments( 'nexopos.update.orders-instalments' ) );
Route::post( 'orders/{order}/void', [ OrdersController::class, 'voidOrder' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.void.orders' ) );
Route::post( 'orders/{order}/tax-invoice', [ OrdersController::class, 'issueTaxInvoice' ] )->where( 'order', '[0-9]+' )->middleware( NsRestrictMiddleware::arguments( 'nexopos.create.tax-invoices' ) );
Route::get( 'orders/{order}/tax-invoices', [ OrdersController::class, 'listOrderTaxInvoices' ] )->where( 'order', '[0-9]+' )->middleware( NsRestrictMiddleware::arguments( 'nexopos.read.tax-invoices' ) );
Route::post( 'tax-invoices/{taxInvoice}/cancel', [ OrdersController::class, 'cancelTaxInvoice' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.cancel.tax-invoices' ) );

/**
 * Billing notes are raised per customer rather than per order, because the whole point of
 * the document is to collect a customer's outstanding deliveries onto one sheet.
 */
Route::get( 'customers/{customer}/billable-orders', [ OrdersController::class, 'listBillableOrders' ] )->where( 'customer', '[0-9]+' )->middleware( NsRestrictMiddleware::arguments( 'nexopos.create.billing-notes' ) );
Route::get( 'customers/{customer}/billing-notes', [ OrdersController::class, 'listCustomerBillingNotes' ] )->where( 'customer', '[0-9]+' )->middleware( NsRestrictMiddleware::arguments( 'nexopos.read.billing-notes' ) );
Route::post( 'customers/{customer}/billing-note', [ OrdersController::class, 'issueBillingNote' ] )->where( 'customer', '[0-9]+' )->middleware( NsRestrictMiddleware::arguments( 'nexopos.create.billing-notes' ) );
Route::post( 'billing-notes/{billingNote}/cancel', [ OrdersController::class, 'cancelBillingNote' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.cancel.billing-notes' ) );
Route::post( 'orders', [ OrdersController::class, 'create' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.create.orders' ) );
Route::post( 'orders/{id}/products', [ OrdersController::class, 'addProductToOrder' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.update.orders' ) );
Route::post( 'orders/{order}/processing', [ OrdersController::class, 'changeOrderProcessingStatus' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.update.orders' ) );
Route::post( 'orders/{order}/delivery', [ OrdersController::class, 'changeOrderDeliveryStatus' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.update.orders' ) );
Route::post( 'orders/{order}/payments', [ OrdersController::class, 'addPayment' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.make-payment.orders' ) );
Route::post( 'orders/{order}/refund', [ OrdersController::class, 'makeOrderRefund' ] )
    ->middleware( NsRestrictMiddleware::arguments( 'nexopos.refund.orders' ) );
Route::post( 'orders/{order}/instalments', [ OrdersController::class, 'createInstalment' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.create.orders-instalments' ) );

Route::put( 'orders/{order}/instalments/{instalment}', [ OrdersController::class, 'updateInstalment' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.update.orders-instalments' ) );
Route::put( 'orders/{id}', [ OrdersController::class, 'updateOrder' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.update.orders' ) );

Route::delete( 'orders/{order}/instalments/{instalment}', [ OrdersController::class, 'deleteInstalment' ] )
    ->middleware( NsRestrictMiddleware::arguments( 'nexopos.delete.orders-instalments' ) );
Route::delete( 'orders/{order}', [ OrdersController::class, 'deleteOrder' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.delete.orders' ) );
Route::delete( 'orders/{id}/products/{product_id}', [ OrdersController::class, 'deleteOrderProduct' ] )->where( 'id', '[0-9]+' )->middleware( NsRestrictMiddleware::arguments( 'nexopos.update.orders' ) );
