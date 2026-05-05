<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\ShowEmployeeDashboardRequest;
use App\Http\Resources\Dashboard\EmployeeDashboardResource;
use App\Services\Dashboard\EmployeeDashboardService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class EmployeeDashboardController extends Controller
{
    public function __construct(
        protected EmployeeDashboardService $employeeDashboardService,
    ) {}

    public function __invoke(ShowEmployeeDashboardRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: EmployeeDashboardResource::make($this->employeeDashboardService->summary($request->user()))->resolve($request),
            message: 'Employee dashboard fetched successfully.',
        );
    }
}
