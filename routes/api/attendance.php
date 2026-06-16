<?php

use App\Http\Controllers\Api\AttendanceController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('attendance')->group(function () {
        Route::get('/', [AttendanceController::class, 'index']);
        Route::get('/summary', [AttendanceController::class, 'summary']);
        Route::post('/clock-in', [AttendanceController::class, 'clockIn']);
        Route::post('/clock-out', [AttendanceController::class, 'clockOut']);
        Route::post('/proxy-clock-in', [AttendanceController::class, 'proxyClockIn']);
        Route::post('/proxy-clock-out', [AttendanceController::class, 'proxyClockOut']);
        Route::put('/{attendance}/correction', [AttendanceController::class, 'correct']);
        Route::post('/mark-absent', [AttendanceController::class, 'markAbsent']);

        Route::prefix('qr')->group(function () {
            Route::post('/generate', [AttendanceController::class, 'generateQr']);
            Route::get('/current', [AttendanceController::class, 'currentQr']);
            Route::get('/{qrToken}/download', [AttendanceController::class, 'downloadQr']);
            Route::delete('/{qrToken}', [AttendanceController::class, 'destroyQr']);
            Route::post('/scan', [AttendanceController::class, 'scanQr']);
        });
    });
});
