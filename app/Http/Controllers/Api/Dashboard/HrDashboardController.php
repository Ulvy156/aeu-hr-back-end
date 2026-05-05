<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\ShowHrDashboardRequest;
use App\Http\Resources\Dashboard\HrDashboardResource;
use App\Services\Dashboard\HrDashboardService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class HrDashboardController extends Controller
{
    public function __construct(
        protected HrDashboardService $hrDashboardService,
    ) {}

    public function __invoke(ShowHrDashboardRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: HrDashboardResource::make($this->hrDashboardService->summary())->resolve($request),
            message: 'HR dashboard fetched successfully.',
        );
    }
}
