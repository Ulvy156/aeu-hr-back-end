<?php

use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\EmployeeUpgradeRequestController;
use App\Http\Controllers\Api\EmploymentHistoryController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/employees/search', [EmployeeController::class, 'search']);
    Route::get('/employees/hierarchy', [EmployeeController::class, 'hierarchy']);
    Route::get('/employees/{employee}/employment-history', [EmploymentHistoryController::class, 'index']);
    Route::apiResource('employees', EmployeeController::class);

    Route::apiResource('employee-upgrade-requests', EmployeeUpgradeRequestController::class)
        ->parameters(['employee-upgrade-requests' => 'upgradeRequest'])
        ->only(['index', 'store', 'show']);
    Route::post('/employee-upgrade-requests/{upgradeRequest}/approve', [EmployeeUpgradeRequestController::class, 'approve']);
    Route::post('/employee-upgrade-requests/{upgradeRequest}/reject', [EmployeeUpgradeRequestController::class, 'reject']);
    Route::post('/employee-upgrade-requests/{upgradeRequest}/cancel', [EmployeeUpgradeRequestController::class, 'cancel']);
});
