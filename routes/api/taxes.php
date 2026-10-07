<?php

use App\Http\Controllers\Dashboard\TaxesController;
use App\Http\Middleware\NsRestrictMiddleware;
use Illuminate\Support\Facades\Route;

Route::post( 'taxes', [ TaxesController::class, 'post' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.create.taxes' ) );
Route::put( 'taxes/{id}', [ TaxesController::class, 'put' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.update.taxes' ) );
Route::delete( 'taxes/{id}', [ TaxesController::class, 'delete' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.delete.taxes' ) );

Route::middleware( NsRestrictMiddleware::arguments( 'nexopos.read.taxes' ) )->group( function () {
    Route::get( 'taxes/{id?}', [ TaxesController::class, 'get' ] )->where( [ 'id' => '[0-9]+' ] );
    Route::get( 'taxes/groups/{id?}', [ TaxesController::class, 'getTaxGroup' ] );
} );

/**
 * Thai withholding tax. Cancelling a certificate is a POST.
 *
 * It was a GET, and the reason recorded here was that the row action issues a GET with a confirmation —
 * which was true of every triggered action in this application and is exactly what needed correcting. A
 * cancelled certificate keeps its number and its place in the sequence, so cancelling one is a permanent
 * entry in a numbered series that the revenue department reads, and it must not be reachable by following a
 * link. The row action posts now, which the table row supports.
 */
Route::post( 'withholding-tax/{withholdingTax}/cancel', [ TaxesController::class, 'cancelWithholdingTax' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.delete.withholding-tax' ) );
