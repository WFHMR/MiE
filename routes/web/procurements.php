<?php

use App\Http\Controllers\Dashboard\ProcurementController;
use App\Http\Controllers\PaymentVoucherController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\PurchasingWorkflowController;
use App\Http\Controllers\SupplierBillingController;
use App\Http\Middleware\PurchaseReceiptScope;
use Illuminate\Support\Facades\Route;

Route::middleware(PurchaseReceiptScope::class)->group(function (): void {
    Route::get('/procurements', [ProcurementController::class, 'listProcurements'])->name(ns()->routeName('ns.procurement-list')); // @todo update
    Route::get('/procurements/create', [PurchaseOrderController::class, 'create'])->name(ns()->routeName('ns.procurement-create')); // @todo update
    Route::get('/procurements/products', [ProcurementController::class, 'getProcurementProducts'])->name(ns()->routeName('ns.procurement-products')); // @todo update
    Route::get('/procurements/products/edit/{product}', [ProcurementController::class, 'editProcurementProduct'])->name(ns()->routeName('ns.procurement-edit-products')); // @todo update
    Route::get('/procurements/edit/{procurement}', [ProcurementController::class, 'updateProcurement'])->name(ns()->routeName('ns.procurement-edit')); // @todo update
    Route::get('/procurements/edit/{procurement}/invoice', [ProcurementController::class, 'procurementInvoice'])->name(ns()->routeName('ns.procurement-invoice')); // @todo update

    Route::get('/payment-vouchers', [PaymentVoucherController::class, 'index'])->name('ns.payment-vouchers');
    Route::post('/payment-vouchers', [PaymentVoucherController::class, 'store'])->name('ns.payment-vouchers.store');
    Route::get('/payment-vouchers/{id}', [PaymentVoucherController::class, 'show'])->whereNumber('id')->name('ns.payment-vouchers.show');
    Route::post('/payment-vouchers/{id}/confirm', [PaymentVoucherController::class, 'confirm'])->whereNumber('id')->name('ns.payment-vouchers.confirm');
    Route::post('/payment-vouchers/{id}/cancel', [PaymentVoucherController::class, 'cancel'])->whereNumber('id')->name('ns.payment-vouchers.cancel');
    Route::get('/billing-approval', [PurchaseOrderController::class, 'billingQueue'])->name('ns.billing-approval');
    Route::post('/billing-approval/{id}', [PurchaseOrderController::class, 'confirmBilling'])->whereNumber('id')->name('ns.billing-approval.confirm');
    Route::get('/supplier-billing', [SupplierBillingController::class, 'index'])->name('ns.supplier-billing');
    Route::get('/purchase-paperwork/{id}', [PurchaseOrderController::class, 'paperwork'])->whereNumber('id')->name('ns.purchase-paperwork');
    Route::post('/purchase-paperwork/{id}', [PurchaseOrderController::class, 'savePaperwork'])->whereNumber('id')->name('ns.purchase-paperwork.save');
    Route::get('/purchase-trials', [PurchaseOrderController::class, 'trials'])->name('ns.purchase-trials');
    Route::post('/purchase-trials/{id}', [PurchaseOrderController::class, 'archiveTrial'])->whereNumber('id')->name('ns.purchase-trials.archive');
    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->name('ns.purchase-orders');
    Route::get('/purchase-orders/create', [PurchaseOrderController::class, 'create'])->name('ns.purchase-orders.create');
    Route::get('/purchase-orders/{id}/edit', [PurchaseOrderController::class, 'create'])->whereNumber('id')->name('ns.purchase-orders.edit');
    Route::get('/purchase-orders/{id}', [PurchaseOrderController::class, 'show'])->whereNumber('id')->name('ns.purchase-orders.show');
    Route::post('/purchase-orders/{id}/receive', [PurchaseOrderController::class, 'receive'])->whereNumber('id')->name('ns.purchase-orders.receive');

});

Route::prefix('/purchasing')->controller(PurchasingWorkflowController::class)->group(function (): void {
    Route::get('/requests', 'index')->name('ns.purchasing.requests');
    Route::post('/requests', 'store')->name('ns.purchasing.requests.store');
    Route::get('/products', 'search')->name('ns.purchasing.products');
    Route::get('/requests/{id}', 'show')->whereNumber('id')->name('ns.purchasing.request');
    Route::post('/requests/{id}/decision', 'decide')->whereNumber('id')->name('ns.purchasing.decision');
    Route::post('/requests/{id}/quotes', 'quote')->whereNumber('id')->name('ns.purchasing.quote');
    Route::post('/requests/{id}/select', 'selectQuote')->whereNumber('id')->name('ns.purchasing.select');
    Route::get('/returns', 'returns')->name('ns.purchasing.returns');
    Route::post('/returns', 'storeReturn')->name('ns.purchasing.returns.store');
    Route::get('/returns/{id}', 'showReturn')->whereNumber('id')->name('ns.purchasing.return');
    Route::post('/returns/{id}', 'actionReturn')->whereNumber('id')->name('ns.purchasing.return.action');
    Route::get('/report', 'report')->name('ns.purchasing.report');
});

Route::get('/goods-receipts', [\App\Http\Controllers\GoodsReceiptController::class,'index'])->name('ns.goods-receipts.index');
Route::post('/goods-receipts', [\App\Http\Controllers\GoodsReceiptController::class,'store'])->name('ns.goods-receipts.store');
Route::get('/goods-receipts/{id}', [\App\Http\Controllers\GoodsReceiptController::class,'show'])->whereNumber('id')->name('ns.goods-receipts.show');
Route::post('/goods-receipts/{id}/confirm', [\App\Http\Controllers\GoodsReceiptController::class,'confirm'])->whereNumber('id')->name('ns.goods-receipts.confirm');
Route::post('/goods-receipts/{id}/cancel', [\App\Http\Controllers\GoodsReceiptController::class,'cancel'])->whereNumber('id')->name('ns.goods-receipts.cancel');
