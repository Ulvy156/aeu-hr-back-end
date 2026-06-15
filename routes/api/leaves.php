<?php

use App\Http\Controllers\Api\LeaveController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/leave-balances', [LeaveController::class, 'balances']);
    Route::apiResource('leaves', LeaveController::class)
        ->parameters(['leaves' => 'leave'])
        ->only(['index', 'store', 'show']);
    Route::post('/leaves/{leave}/approve', [LeaveController::class, 'approve']);
    Route::post('/leaves/{leave}/reject', [LeaveController::class, 'reject']);
    Route::post('/leaves/{leave}/cancel', [LeaveController::class, 'cancel']);
});
