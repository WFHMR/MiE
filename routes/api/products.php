<?php

use App\Http\Controllers\Dashboard\ProductsController;
use App\Http\Controllers\InventoryUploadController;
use App\Http\Middleware\NsRestrictMiddleware;
use Illuminate\Support\Facades\Route;

Route::middleware( NsRestrictMiddleware::arguments( 'nexopos.read.products' ) )->group( function () {
    Route::get( 'products', [ ProductsController::class, 'getProduts' ] );
    Route::get( 'products/all/variations', [ ProductsController::class, 'getAllVariations' ] );

    /**
     * The sticker sizes the label page prints on. Readable by anyone who can read the catalogue,
     * because printing a label is not a change to it.
     *
     * Declared above products/{identifier} deliberately: that pattern matches anything, so registered
     * first it answered this request by looking for a product named "label-presets" and failed on a
     * type error deep inside ProductService.
     */
    Route::get( 'products/label-presets', [ ProductsController::class, 'getLabelPresets' ] );

    Route::get( 'products/{identifier}', [ ProductsController::class, 'singleProduct' ] );
    Route::get( 'products/{identifier}/variations', [ ProductsController::class, 'getProductVariations' ] );

    /**
     * These two rewrite a product — one recomputes its prices, the other clears its stock — so they are
     * POST. Nothing in the dashboard calls either, which is why they were still GET and still marked
     * deprecated, but a mutating endpoint reachable by following a link is a mutating endpoint whether the
     * link exists yet or not.
     *
     * @deprecated
     */
    Route::post( 'products/{identifier}/refresh-prices', [ ProductsController::class, 'refreshPrices' ] );
    Route::post( 'products/{identifier}/reset', [ ProductsController::class, 'reset' ] );

    Route::get( 'products/{identifier}/history', [ ProductsController::class, 'history' ] );

    // @todo duplicate of /products/{identifier}/units/quantities
    Route::get( 'products/{identifier}/units', [ ProductsController::class, 'units' ] );
    Route::get( 'products/{product}/units/{unit}/quantity', [ ProductsController::class, 'getUnitQuantity' ] );

    // @todo duplicate of /products/{identifier}/units
    Route::get( 'products/{product}/units/quantities', [ ProductsController::class, 'getUnitQuantities' ] );
    Route::get( 'products/{product}/procurements', [ ProductsController::class, 'getProcuredProducts' ] );
    Route::get( 'products/search/using-barcode/{product}', [ ProductsController::class, 'searchUsingArgument' ] );
} );

/**
 * Counting or receiving goods by scanning the shelf, from the stock upload page.
 *
 * Guarded by the same permission the upload page itself takes, `nexopos.create.products`, because the
 * two do the same thing to the same rows — one from a file, one from a barcode reader.
 *
 * The lookup is a GET because it only reads. The apply is a POST because it writes, which is the rule
 * the whole api was brought into line with: a change must not be reachable by following a link.
 */
Route::middleware( NsRestrictMiddleware::arguments( 'nexopos.create.products' ) )->group( function () {
    /**
     * The barcode travels in the path, not the query, for two reasons. It follows the till's own
     * lookup, `products/search/using-barcode/{product}`, so the two read alike. And TestOtherGetRoutes
     * walks every parameterless GET under api/ and requires it to answer 200 or 403 — a sound rule,
     * since a GET that needs no arguments should be safe to call — which a lookup that cannot work
     * without a barcode would fail. The branch stays in the query because it is a filter, not the
     * subject.
     *
     * `.*` because a barcode in this catalogue contains dots, dashes and asterisks.
     */
    Route::get( 'inventory/scan/lookup/{barcode}', [ InventoryUploadController::class, 'scanLookup' ] )
        ->where( 'barcode', '.*' );

    Route::post( 'inventory/scan/apply', [ InventoryUploadController::class, 'scanApply' ] );
} );

Route::middleware( NsRestrictMiddleware::arguments( 'nexopos.update.products' ) )->group( function () {
    Route::post( 'products/label-presets', [ ProductsController::class, 'saveLabelPreset' ] );
    Route::post( 'products/label-presets/select', [ ProductsController::class, 'selectLabelPreset' ] );
    Route::delete( 'products/label-presets/{name}', [ ProductsController::class, 'deleteLabelPreset' ] )
        ->where( 'name', '.*' );
} );

Route::middleware( [
    NsRestrictMiddleware::arguments( 'nexopos.delete.products' ),
] )->group( function () {
    Route::delete( 'products/{identifier}', [ ProductsController::class, 'deleteProduct' ] );
    Route::delete( 'products/units/quantity/{unitQuantity}', [ ProductsController::class, 'deleteUnitQuantity' ] );

    /**
     * DELETE products/all/variations pointed at ProductsController::deleteAllVariations, which does not
     * exist, so the route answered 500 to anyone authorised to call it. Nothing in the dashboard calls it;
     * variations are removed one at a time through products/{identifier}/variations/{variation_id} below.
     */
    Route::delete( 'products/{identifier}/variations/{variation_id}', [ ProductsController::class, 'deleteSingleVariation' ] );
    Route::delete( 'products', [ ProductsController::class, 'deleteAllProducts' ] );
} );

Route::post( 'products', [ ProductsController::class, 'saveProduct' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.create.products' ) );
Route::post( 'products/search', [ ProductsController::class, 'searchProduct' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.read.products' ) );
Route::post( 'products/adjustments', [ ProductsController::class, 'createAdjustment' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.update.products' ) );
Route::post( 'products/adjustments/draft', [ ProductsController::class, 'saveDraftAdjustment' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.update.products' ) );
Route::get( 'products/adjustments/{adjustment}', [ ProductsController::class, 'getAdjustment' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.update.products' ) );
Route::put( 'products/adjustments/{adjustment}', [ ProductsController::class, 'updateDraftAdjustment' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.update.products' ) );
/**
 * Executing an adjustment writes the counted figures onto the shelves permanently, so a POST. As a GET it
 * was reachable by following a link, and the thing on the other end of that link changes stock.
 */
Route::post( 'products/adjustments/{adjustment}/execute', [ ProductsController::class, 'executeAdjustment' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.update.products' ) );
Route::delete( 'products/adjustments/{adjustment}', [ ProductsController::class, 'deleteDraftAdjustment' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.update.products' ) );
Route::post( 'products/{identifier}/variations/{variation_id}', [ ProductsController::class, 'createSingleVariation' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.create.products' ) );
Route::post( 'products/{product}/units/conversion', [ ProductsController::class, 'convertUnits' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.update.products' ) );
Route::post( 'products/reorder', [ ProductsController::class, 'reorderProducts' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.update.products' ) );

Route::put( 'products/{identifier}/variations/{variation_id}', [ ProductsController::class, 'editSingleVariation' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.update.products' ) );
Route::put( 'products/{product}', [ ProductsController::class, 'updateProduct' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.update.products' ) );
