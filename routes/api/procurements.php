<?php

use App\Http\Controllers\Dashboard\ProcurementController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Middleware\NsRestrictMiddleware;
use App\Http\Middleware\PurchaseReceiptScope;
use Illuminate\Support\Facades\Route;

Route::middleware( PurchaseReceiptScope::class )->group( function (): void {
    Route::middleware( NsRestrictMiddleware::arguments( 'nexopos.read.procurements' ) )->group( function () {
        Route::get( 'procurements/{id?}', [ProcurementController::class, 'list'] )->where( 'id', '[0-9]+' );
        Route::get( 'procurements/{id}/products', [ProcurementController::class, 'procurementProducts'] );
        /** Recomputes and rewrites the purchase's totals, so a POST rather than a link. */
        Route::post( 'procurements/{id}/refresh', [ProcurementController::class, 'refreshProcurement'] );
        Route::get( 'procurements/preload/{uuid}', [ProcurementController::class, 'preload'] );
        Route::get( 'procurements/low-stock-suggestions', [ProcurementController::class, 'getLowStockSuggestions'] );
    } );

    /**
     * Marking a purchase as paid moves money in the books, so it is a POST. It was a GET, reached by a plain
     * link from the row action, which means anything that follows links on a signed-in session could settle a
     * supplier invoice: a browser prefetch, a bookmark opened later, a url pasted into a chat that unfurls it.
     * The confirm dialog on the row guards the mouse, not the request.
     */
    Route::post( 'procurements/{procurement}/set-as-paid', [ProcurementController::class, 'setAsPaid'] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.update.procurements' ) );

    Route::middleware( NsRestrictMiddleware::arguments( 'nexopos.create.procurements' ) )->group( function () {
        Route::post( 'procurements/preload', [ProcurementController::class, 'storePreload'] );
        Route::post( 'procurements/{id}/products', [ProcurementController::class, 'procure'] );
        Route::post( 'procurements', [ProcurementController::class, 'create'] );
        Route::post( 'procurements/products/search-procurement-product', [ProcurementController::class, 'searchProcurementProduct'] );
        Route::post( 'procurements/products/search-product', [ProcurementController::class, 'searchProduct'] );
    } );

    Route::middleware( NsRestrictMiddleware::arguments( 'nexopos.update.procurements' ) )->group( function () {
        Route::put( 'procurements/{procurement}', [ProcurementController::class, 'edit'] );
        Route::put( 'procurements/{procurement}/change-payment-status', [ProcurementController::class, 'changePaymentStatus'] );
        Route::put( 'procurements/{id}/products/{product_id}', [ProcurementController::class, 'editProduct'] );
        Route::put( 'procurements/{id}/products', [ProcurementController::class, 'bulkUpdateProducts'] );
    } );

    Route::middleware( NsRestrictMiddleware::arguments( 'nexopos.delete.procurements' ) )->group( function () {
        Route::delete( 'procurements/{id}/products/{product_id}', [ProcurementController::class, 'deleteProcurementProduct'] );
        Route::delete( 'procurements/{id}', [ProcurementController::class, 'deleteProcurement'] )->where( 'id', '[0-9]+' );
    } );

    Route::post( 'purchase-orders/search', [PurchaseOrderController::class, 'search'] );
    Route::get( 'purchase-orders/form/{id?}', [PurchaseOrderController::class, 'form'] )->whereNumber( 'id' );
    Route::post( 'purchase-orders/{id?}', [PurchaseOrderController::class, 'save'] )->whereNumber( 'id' );

} );
