<?php

use App\Http\Controllers\Api\AnnouncementCategoryController;
use App\Http\Controllers\Api\AnnouncementController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('announcement-categories', AnnouncementCategoryController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy']);

    Route::apiResource('announcements', AnnouncementController::class)
        ->only(['index', 'store', 'show', 'update']);
    Route::post('/announcements/{announcement}/submit', [AnnouncementController::class, 'submit']);
    Route::post('/announcements/{announcement}/cancel-submission', [AnnouncementController::class, 'cancelSubmission']);
    Route::post('/announcements/{announcement}/approve', [AnnouncementController::class, 'approve']);
    Route::post('/announcements/{announcement}/reject', [AnnouncementController::class, 'reject']);
    Route::post('/announcements/{announcement}/archive', [AnnouncementController::class, 'archive']);
    Route::post('/announcements/{announcement}/read', [AnnouncementController::class, 'read']);
});
