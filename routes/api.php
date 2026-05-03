<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanySettingController;
use App\Http\Controllers\Api\Dashboard\UserSummaryController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\PositionController;
use App\Http\Controllers\Api\PublicHolidayController;
use App\Http\Controllers\Api\RolePermissionController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:login')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::prefix('attendance')->group(function () {
        Route::get('/', [AttendanceController::class, 'index']);
        Route::post('/clock-in', [AttendanceController::class, 'clockIn']);
        Route::post('/clock-out', [AttendanceController::class, 'clockOut']);
        Route::put('/{attendance}/correction', [AttendanceController::class, 'correct']);
        Route::post('/mark-absent', [AttendanceController::class, 'markAbsent']);
    });
    Route::prefix('dashboard')->group(function () {
        Route::get('/users-summary', UserSummaryController::class)
            ->middleware('permission:dashboards.admin_view');
    });
    Route::get('/audit-logs', [AuditLogController::class, 'index']);
    Route::get('/settings/company', [CompanySettingController::class, 'show']);
    Route::put('/settings/company', [CompanySettingController::class, 'update']);
    Route::apiResource('public-holidays', PublicHolidayController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('departments', DepartmentController::class);
    Route::apiResource('positions', PositionController::class);
    Route::apiResource('employees', EmployeeController::class);

    Route::middleware('role:admin')->group(function () {
        Route::apiResource('users', UserController::class);
        Route::put('/users/{user}/roles', [RolePermissionController::class, 'syncUserRoles'])
            ->middleware('permission:users.assign_roles');
        Route::get('/roles', [RolePermissionController::class, 'roles'])
            ->middleware('permission:roles_permissions.roles_view');
        Route::get('/permissions', [RolePermissionController::class, 'permissions'])
            ->middleware('permission:roles_permissions.permissions_view');
    });
});
