<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\ShowCeoDashboardRequest;
use App\Http\Resources\Dashboard\CeoDashboardResource;
use App\Services\Dashboard\CeoDashboardService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class CeoDashboardController extends Controller
{
    public function __construct(
        protected CeoDashboardService $ceoDashboardService,
    ) {}

    public function __invoke(ShowCeoDashboardRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: CeoDashboardResource::make($this->ceoDashboardService->summary())->resolve($request),
            message: 'CEO dashboard fetched successfully.',
        );
    }
}
