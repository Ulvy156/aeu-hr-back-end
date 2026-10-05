<?php

use App\Http\Controllers\Api\AnnouncementCategoryController;
use App\Http\Controllers\Api\AnnouncementController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('announcement-categories', AnnouncementCategoryController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy']);

    Route::apiResource('announcements', AnnouncementController::class)
        ->only(['index', 'store', 'show', 'update']);
    Route::post('/announcements/{announcement}/publish', [AnnouncementController::class, 'publish']);
    Route::post('/announcements/{announcement}/archive', [AnnouncementController::class, 'archive']);
    Route::post('/announcements/{announcement}/read', [AnnouncementController::class, 'read']);
});
