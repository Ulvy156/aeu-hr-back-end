<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompanySetting\UpdateCompanySettingRequest;
use App\Http\Resources\CompanySettingResource;
use App\Services\CompanySettingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanySettingController extends Controller
{
    public function __construct(
        protected CompanySettingService $companySettingService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $setting = $this->companySettingService->current();

        $this->authorize('view', $setting);

        return ApiResponse::success(
            data: CompanySettingResource::make($setting)->resolve($request),
            message: 'Company settings fetched successfully.',
        );
    }

    public function update(UpdateCompanySettingRequest $request): JsonResponse
    {
        $setting = $this->companySettingService->current();

        $this->authorize('update', $setting);

        $setting = $this->companySettingService->update(
            attributes: $request->safe()->except('company_logo'),
            companyLogo: $request->file('company_logo'),
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: CompanySettingResource::make($setting)->resolve($request),
            message: 'Company settings updated successfully.',
        );
    }
}
