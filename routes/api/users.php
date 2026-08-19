<?php

use App\Http\Controllers\Api\RolePermissionController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserPermissionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::middleware('role:admin|hr')->group(function () {
        Route::get('/users/search', [UserController::class, 'search']);
        Route::apiResource('users', UserController::class)->only(['index']);
    });

    Route::middleware('role:admin')->group(function () {
        Route::apiResource('users', UserController::class)->only(['show', 'store', 'update', 'destroy']);
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])
            ->middleware('permission:users.reset_password');
        Route::put('/users/{user}/roles', [RolePermissionController::class, 'syncUserRoles'])
            ->middleware('permission:users.assign_roles');
        Route::get('/users/{user}/permissions', [UserPermissionController::class, 'show'])
            ->middleware('permission:users.view');
        Route::put('/users/{user}/permissions', [UserPermissionController::class, 'sync'])
            ->middleware('permission:users.assign_permissions');
        Route::post('/users/{user}/permissions', [UserPermissionController::class, 'store'])
            ->middleware('permission:users.assign_permissions');
        Route::delete('/users/{user}/permissions', [UserPermissionController::class, 'destroy'])
            ->middleware('permission:users.assign_permissions');
        Route::get('/roles', [RolePermissionController::class, 'roles'])
            ->middleware('permission:roles_permissions.roles_view');
        Route::get('/permissions', [RolePermissionController::class, 'permissions'])
            ->middleware('permission:roles_permissions.permissions_view');
        Route::patch('/permissions/{permission}', [RolePermissionController::class, 'updatePermissionDescription'])
            ->middleware('permission:roles_permissions.manage');
    });
});
