<?php

use Illuminate\Support\Facades\Route;

Route::prefix('api/nexohr')->middleware(['api', 'auth:sanctum'])->group(function () {
    // API routes here
});
