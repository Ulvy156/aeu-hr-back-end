<?php

use App\Http\Controllers\Api\RecruitmentCandidateController;
use App\Http\Controllers\Api\RecruitmentVacancyController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('recruitment')->group(function () {
        Route::apiResource('vacancies', RecruitmentVacancyController::class)
            ->parameters(['vacancies' => 'vacancy'])
            ->only(['index', 'store', 'show', 'update']);
        Route::post('/vacancies/{vacancy}/close', [RecruitmentVacancyController::class, 'close']);

        Route::apiResource('candidates', RecruitmentCandidateController::class)
            ->parameters(['candidates' => 'candidate'])
            ->only(['index', 'store', 'show', 'update']);
        Route::post('/candidates/{candidate}/status', [RecruitmentCandidateController::class, 'updateStatus']);
    });
});
