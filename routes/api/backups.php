<?php

use App\Http\Controllers\Api\BackupController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/backups/download', [BackupController::class, 'download']);
});
