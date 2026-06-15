<?php

use App\Http\Controllers\Api\Reports\AttendanceReportController;
use App\Http\Controllers\Api\Reports\LeaveReportController;
use App\Http\Controllers\Api\Reports\PayrollReportController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('reports')->group(function () {
        Route::get('/payroll', [PayrollReportController::class, 'index'])
            ->middleware('permission:reports.payroll_view');
        Route::get('/payroll/export', [PayrollReportController::class, 'export'])
            ->middleware('permission:reports.payroll_export');
        Route::get('/attendance', [AttendanceReportController::class, 'index'])
            ->middleware('permission:reports.attendance_view');
        Route::get('/attendance/export', [AttendanceReportController::class, 'export'])
            ->middleware('permission:reports.attendance_export');
        Route::get('/leave', [LeaveReportController::class, 'index'])
            ->middleware('permission:reports.leave_view');
        Route::get('/leave/export', [LeaveReportController::class, 'export'])
            ->middleware('permission:reports.leave_export');
    });
});
