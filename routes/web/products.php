<?php

use App\Http\Controllers\Dashboard\CategoryController;
use App\Http\Controllers\Dashboard\ProductCostEditorController;
use App\Http\Controllers\Dashboard\ProductsController;
use App\Http\Controllers\InventoryUploadController;
use App\Http\Controllers\StockCountController;
use Illuminate\Support\Facades\Route;

Route::get('/products', [ProductsController::class, 'listProducts'])->name(ns()->routeName('ns.dashboard.products'));

/**
 * แก้ต้นทุนสินค้าหลายรายการ.
 *
 * Declared ahead of /products/{product}/... so the literal segment is never read as a product
 * identifier, whatever order the wildcard routes end up in later.
 */
Route::get('/products/cost-editor', [ProductCostEditorController::class, 'index'])->name('ns.products-cost-editor');
Route::post('/products/cost-editor', [ProductCostEditorController::class, 'save'])->name('ns.products-cost-editor.save');
Route::get('/products/create', [ProductsController::class, 'createProduct'])->name(ns()->routeName('ns.dashboard.products.create'));
Route::get('/products/stock-adjustment', [ProductsController::class, 'showStockAdjustment'])->name(ns()->routeName('ns.dashboard.products.stock-adjustment'));
Route::get('/products/stock-adjustment/{history}', [ProductsController::class, 'showStockAdjustmentEdit'])->name(ns()->routeName('ns.dashboard.products.stock-adjustment.edit'));
Route::get('/products/adjustment-history', [ProductsController::class, 'listAdjustmentHistory'])->name(ns()->routeName('ns.dashboard.products.adjustment-history'));
Route::get('/products/print-labels', [ProductsController::class, 'printLabels'])->name(ns()->routeName('ns.dashboard.products.print-labels'));
/**
 * Barcode images for the label page, drawn on demand.
 *
 * They used to be read straight off the public disk, where they only appear when a product is saved
 * through the product form or received through a procurement. A catalogue loaded by CSV import
 * therefore had none: 2,922 of the shop's 2,928 barcodes had no image, and every label printed a
 * broken picture. The pattern allows dots because barcodes in this catalogue contain them.
 */
Route::get('/products/barcode/{barcode}', [ProductsController::class, 'showBarcodeImage'])
    ->where('barcode', '.*')
    ->name(ns()->routeName('ns.dashboard.products.barcode'));
Route::get('/products/edit/{product}', [ProductsController::class, 'editProduct'])->name(ns()->routeName('ns.dashboard.products.edit')); // @todo update
Route::get('/products/{product}/units', [ProductsController::class, 'productUnits'])->name(ns()->routeName('ns.dashboard.products.units'));
Route::get('/products/{product}/history', [ProductsController::class, 'productHistory'])->name(ns()->routeName('ns.dashboard.products.history'));
Route::get('/products/categories', [CategoryController::class, 'listCategories'])->name(ns()->routeName('ns.dashboard.products.categories'));
Route::get('/products/categories/create', [CategoryController::class, 'createCategory'])->name(ns()->routeName('ns.dashboard.products.categories.create'));
Route::get('/products/categories/edit/{category}', [CategoryController::class, 'editCategory'])->name(ns()->routeName('ns.dashboard.categories.edit'));
Route::get('/products/categories/compute-products/{category}', [CategoryController::class, 'computeCategoryProducts'])->name(ns()->routeName('ns.dashboard.products.categories.compute'));
Route::get('/products/stock-flow-records', [CategoryController::class, 'showStockFlowCrud'])->name(ns()->routeName('ns.dashboard.products.stock-flow-records'));
Route::get('/products/scale-range', [ProductsController::class, 'getScaleRange'])->name(ns()->routeName('ns.dashboard.products.scale-range'));
Route::get('/products/scale-range/create', [ProductsController::class, 'createScaleRange'])->name(ns()->routeName('ns.dashboard.products.scale-range.create'));
Route::get('/products/scale-range/edit/{scaleRange}', [ProductsController::class, 'editScaleRange'])->name(ns()->routeName('ns.dashboard.products.scale-range.edit'));

// CSV Upload Routes
Route::get('/inventory/upload', [InventoryUploadController::class, 'index'])->name(ns()->routeName('ns.dashboard.inventory.upload'));
Route::post('/inventory/upload', [InventoryUploadController::class, 'upload'])->name(ns()->routeName('ns.dashboard.inventory.upload.post'));

Route::get('/inventory/counts', [StockCountController::class, 'index'])->name('ns.dashboard.inventory.counts');
Route::post('/inventory/counts', [StockCountController::class, 'create'])->name('ns.dashboard.inventory.counts.create');
Route::get('/inventory/counts/{id}', [StockCountController::class, 'show'])->whereNumber('id')->name('ns.dashboard.inventory.counts.show');
Route::post('/inventory/counts/{id}', [StockCountController::class, 'update'])->whereNumber('id')->name('ns.dashboard.inventory.counts.update');
