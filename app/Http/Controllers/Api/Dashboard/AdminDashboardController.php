<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\ShowAdminDashboardRequest;
use App\Http\Resources\Dashboard\AdminDashboardResource;
use App\Services\Dashboard\AdminDashboardService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AdminDashboardController extends Controller
{
    public function __construct(
        protected AdminDashboardService $adminDashboardService,
    ) {}

    public function __invoke(ShowAdminDashboardRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: AdminDashboardResource::make($this->adminDashboardService->summary())->resolve($request),
            message: 'Admin dashboard fetched successfully.',
        );
    }
}
