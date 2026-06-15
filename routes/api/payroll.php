<?php

use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\PayslipController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('payrolls', PayrollController::class)
        ->parameters(['payrolls' => 'payroll'])
        ->only(['index', 'store', 'show', 'update']);
    Route::post('/payrolls/{payroll}/submit', [PayrollController::class, 'submit']);
    Route::post('/payrolls/{payroll}/approve', [PayrollController::class, 'approve']);
    Route::post('/payrolls/{payroll}/reject', [PayrollController::class, 'reject']);

    Route::apiResource('payslips', PayslipController::class)
        ->parameters(['payslips' => 'payslip'])
        ->only(['index', 'show']);
    Route::get('/payslips/{payslip}/download', [PayslipController::class, 'download']);
});
