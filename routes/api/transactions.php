<?php

use App\Http\Controllers\Dashboard\TransactionController;
use App\Http\Middleware\NsRestrictMiddleware;
use Illuminate\Support\Facades\Route;

Route::middleware( NsRestrictMiddleware::arguments( 'nexopos.read.transactions' ) )->group( function () {
    Route::get( 'transactions/{id?}', [ TransactionController::class, 'get' ] )->where( 'id', '[0-9]+' );
    Route::get( 'transactions/configurations/{transaction?}', [ TransactionController::class, 'getConfigurations' ] );
    Route::get( 'transactions/rules', [ TransactionController::class, 'getRules' ] );
} );

/**
 * Both of these write to the books — one runs a transaction, the other creates its offsetting entry — so
 * both are POST. They were GET, reached by a link from the row action, so anything that follows links on a
 * signed-in session could post to the ledger.
 */
Route::post( 'transactions/trigger/{transaction?}', [ TransactionController::class, 'triggerTransaction' ] )->where( 'id', '[0-9]+' )->middleware( NsRestrictMiddleware::arguments( 'nexopos.update.transactions' ) );
Route::post( 'transactions/history/{history}/create-reflection', [ TransactionController::class, 'createReflection' ] )->where( 'id', '[0-9]+' )->middleware( NsRestrictMiddleware::arguments( 'nexopos.create.transactions-history' ) );

Route::post( 'transactions', [ TransactionController::class, 'post' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.create.transactions' ) );
Route::post( 'transactions/rules', [ TransactionController::class, 'saveRule' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.update.transactions' ) );
Route::put( 'transactions/{id}', [ TransactionController::class, 'put' ] )->where( 'id', '[0-9]+' )->middleware( NsRestrictMiddleware::arguments( 'nexopos.update.transactions' ) );
Route::delete( 'transactions/{transaction}', [ TransactionController::class, 'delete' ] )->where( 'transaction', '[0-9]+' )->middleware( NsRestrictMiddleware::arguments( 'nexopos.delete.transactions' ) );

Route::middleware( NsRestrictMiddleware::arguments( 'nexopos.read.transactions-account' ) )->group( function () {
    Route::get( 'transactions-accounts/{id?}', [ TransactionController::class, 'getExpensesCategories' ] )->where( 'id', '[0-9]+' );
    Route::get( 'transactions-accounts/sub-accounts', [ TransactionController::class, 'getSubAccounts' ] )->where( 'id', '[0-9]+' );
    Route::get( 'transactions-accounts/actions', [ TransactionController::class, 'getActions' ] )->where( 'id', '[0-9]+' );
    Route::get( 'transactions-accounts/{id}/history', [ TransactionController::class, 'getTransactionAccountsHistory' ] );
    Route::post( 'transactions-accounts/category-identifier', [ TransactionController::class, 'getTransactionAccountFromCategory' ] )->name( ns()->routeName( 'ns.transactions-account.category-identifier' ) );
} );

/** Rewrites the chart of accounts back to the defaults, so a POST rather than a link. */
Route::post( 'transactions-accounts/reset-defaults', [ TransactionController::class, 'resetDefaultAccounts' ] )->name( ns()->routeName( 'ns.transactions-account.reset-defaults' ) )->middleware( NsRestrictMiddleware::arguments( 'nexopos.update.transactions-account' ) );
Route::post( 'transactions-accounts', [ TransactionController::class, 'postTransactionsAccount' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.create.transactions-account' ) );
Route::put( 'transactions-accounts/{account}', [ TransactionController::class, 'putTransactionAccount' ] )->where( 'id', '[0-9]+' )->middleware( NsRestrictMiddleware::arguments( 'nexopos.update.transactions-account' ) );
Route::delete( 'transactions-accounts/{account}', [ TransactionController::class, 'deleteAccount' ] )->where( 'id', '[0-9]+' )->middleware( NsRestrictMiddleware::arguments( 'nexopos.delete.transactions-account' ) );
