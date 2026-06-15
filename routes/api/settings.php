<?php

use App\Http\Controllers\Api\CompanySettingController;
use App\Http\Controllers\Api\PublicHolidayController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/settings/company', [CompanySettingController::class, 'show']);
    Route::put('/settings/company', [CompanySettingController::class, 'update']);

    Route::apiResource('public-holidays', PublicHolidayController::class)->only(['index', 'store', 'update', 'destroy']);
});
