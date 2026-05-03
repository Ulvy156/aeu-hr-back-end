<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\UserSummaryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class UserSummaryController extends Controller
{
    public function __construct(
        protected UserSummaryService $userSummaryService,
    ) {}

    public function __invoke(): JsonResponse
    {
        return ApiResponse::success(
            data: $this->userSummaryService->summary(),
            message: 'User summary fetched successfully',
        );
    }
}
