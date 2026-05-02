<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\PositionController;
use App\Http\Controllers\Api\RolePermissionController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:login')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/audit-logs', [AuditLogController::class, 'index']);
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
