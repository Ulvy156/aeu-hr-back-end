<?php

use App\Http\Controllers\Api\Dashboard\AdminDashboardController;
use App\Http\Controllers\Api\Dashboard\CeoDashboardController;
use App\Http\Controllers\Api\Dashboard\EmployeeDashboardController;
use App\Http\Controllers\Api\Dashboard\HrDashboardController;
use App\Http\Controllers\Api\Dashboard\UserSummaryController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('dashboard')->group(function () {
        Route::get('/employee', EmployeeDashboardController::class)
            ->middleware('permission:dashboards.employee_view');
        Route::get('/hr', HrDashboardController::class)
            ->middleware('permission:dashboards.hr_view');
        Route::get('/ceo', CeoDashboardController::class)
            ->middleware('permission:dashboards.ceo_view');
        Route::get('/admin', AdminDashboardController::class)
            ->middleware('permission:dashboards.admin_view');
        Route::get('/users-summary', UserSummaryController::class)
            ->middleware('permission:dashboards.admin_view');
    });
});
