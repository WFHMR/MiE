<?php

use Illuminate\Support\Facades\Route;
use Modules\NexoHR\Http\Controllers\Admin\DashboardController;
use Modules\NexoHR\Http\Controllers\Admin\AttendanceController;
use Modules\NexoHR\Http\Controllers\Admin\OvertimeController;
use Modules\NexoHR\Http\Controllers\Admin\LeaveController;
use Modules\NexoHR\Http\Controllers\Admin\PayrollController;
use Modules\NexoHR\Http\Controllers\Admin\QRCodeController;
use Modules\NexoHR\Http\Controllers\Admin\SettingsController;
use Modules\NexoHR\Http\Controllers\Admin\OfficeLocationController;

Route::prefix('admin')->middleware(['web', 'auth'])->group(function () {
    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('nexohr.admin.dashboard');
    
    // Debug page
    Route::get('/debug', function () {
        return view('NexoHR::admin.debug', ['title' => __('Debug - ตรวจสอบระบบ')]);
    })->name('nexohr.admin.debug');

    // Attendance
    Route::resource('/attendances', AttendanceController::class)->names('nexohr.admin.attendances');

    // Overtime
    Route::resource('/overtimes', OvertimeController::class)->names('nexohr.admin.overtimes');

    // Leave
    Route::resource('/leaves', LeaveController::class)->names('nexohr.admin.leaves');

    // Payroll
    Route::resource('/payrolls', PayrollController::class)->names('nexohr.admin.payrolls');

    // QR Code
    Route::prefix('/qr-code')->group(function () {
        Route::get('/', [QRCodeController::class, 'index'])->name('nexohr.admin.qr-code.index');
    });

    // Settings
    Route::prefix('/settings')->group(function () {
        Route::get('/', [SettingsController::class, 'index'])->name('nexohr.admin.settings.index');
    });

    // Office Locations
    Route::resource('/office-locations', OfficeLocationController::class)->names('nexohr.admin.office-locations');
});
