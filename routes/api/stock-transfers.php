<?php

/**
 * Approving and cancelling a branch-to-branch stock transfer.
 *
 * These were GET routes under the dashboard prefix, reached by a plain link from the row action, so
 * following that link moved goods between branches. Anything that follows links on a signed-in session
 * would do it: a browser prefetch, a bookmark opened later, a url pasted into a chat that unfurls it.
 * The confirm dialog on the row guarded the mouse, not the request.
 *
 * They sit here as POST routes for the same reason every other row action that changes something does:
 * this group authenticates with a token, is exempt from CSRF on purpose, and answers JSON, which is what
 * the table row expects back.
 */

use App\Http\Controllers\Dashboard\StockTransferController;
use App\Http\Middleware\NsRestrictMiddleware;
use Illuminate\Support\Facades\Route;

Route::post( 'stock-transfers/{id}/approve', [ StockTransferController::class, 'approve' ] )
    ->middleware( NsRestrictMiddleware::arguments( 'nexopos.update.stock-transfers' ) )
    ->name( 'ns-api.stock-transfers.approve' );

Route::post( 'stock-transfers/{id}/cancel', [ StockTransferController::class, 'cancel' ] )
    ->middleware( NsRestrictMiddleware::arguments( 'nexopos.update.stock-transfers' ) )
    ->name( 'ns-api.stock-transfers.cancel' );
