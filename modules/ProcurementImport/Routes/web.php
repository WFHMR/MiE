<?php

use Illuminate\Support\Facades\Route;
use Modules\ProcurementImport\Http\Controllers\CsvImportController;
use Modules\ProcurementImport\Http\Controllers\ImportHistoryController;

/*
|--------------------------------------------------------------------------
| Web Routes - Procurement Import Module
|--------------------------------------------------------------------------
*/

Route::middleware(['auth'])->prefix('procurement-import')->name('procurement-import.')->group(function () {
    
    // CSV Import Routes
    Route::get('/', [CsvImportController::class, 'index'])->name('index');
    Route::post('/upload', [CsvImportController::class, 'upload'])->name('upload');
    Route::get('/preview/{batchId}', [CsvImportController::class, 'preview'])->name('preview');
    Route::post('/import/{batchId}', [CsvImportController::class, 'import'])->name('import');
    Route::get('/result/{batchId}', [CsvImportController::class, 'result'])->name('result');
    
    // Import History Routes
    Route::get('/history', [ImportHistoryController::class, 'index'])->name('history');
    Route::get('/history/{batchId}', [ImportHistoryController::class, 'show'])->name('history.show');
    Route::delete('/history/{batchId}', [ImportHistoryController::class, 'destroy'])->name('history.destroy');
    Route::get('/history/{batchId}/export', [ImportHistoryController::class, 'export'])->name('history.export');
    
});
