<?php

use App\Http\Controllers\Dashboard\StockTransferController;
use Illuminate\Support\Facades\Route;

/**
 * Pages only. Approving and cancelling used to sit here as GET routes, which meant a link could move
 * stock between branches: anything that follows links on a signed-in session — a prefetch, a bookmark,
 * a url pasted into a chat — performed the transfer, and the confirm dialog on the row action guarded
 * the mouse rather than the request. Both are POST routes in the api group now, alongside every other
 * row action that changes something.
 */
Route::get( '/stock-transfers', [ StockTransferController::class, 'list' ] )
    ->name( 'ns.dashboard.stock-transfers' );

Route::get( '/stock-transfers/create', [ StockTransferController::class, 'create' ] )
    ->name( 'ns.dashboard.stock-transfers.create' );

Route::get( '/stock-transfers/edit/{id}', [ StockTransferController::class, 'edit' ] )
    ->name( 'ns.dashboard.stock-transfers.edit' );
