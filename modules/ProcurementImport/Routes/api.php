<?php

use Illuminate\Support\Facades\Route;
use Modules\ProcurementImport\Http\Controllers\BranchSelectorController;
use Modules\ProcurementImport\Http\Controllers\CsvImportController;

/*
|--------------------------------------------------------------------------
| API Routes - Procurement Import Module
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum'])->prefix('procurement-import')->group(function () {
    
    // Branch Selection API
    Route::get('/branches', [BranchSelectorController::class, 'index']);
    Route::get('/branches/current', [BranchSelectorController::class, 'getCurrent']);
    Route::post('/branches/set-current', [BranchSelectorController::class, 'setCurrent']);
    Route::post('/branches/clear', [BranchSelectorController::class, 'clear']);
    
    // Import Progress API
    Route::get('/import/{batchId}/progress', [CsvImportController::class, 'getProgress']);
    Route::post('/import/{batchId}/cancel', [CsvImportController::class, 'cancel']);
    
});
