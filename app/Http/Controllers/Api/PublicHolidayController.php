<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicHoliday\IndexPublicHolidayRequest;
use App\Http\Requests\PublicHoliday\StorePublicHolidayRequest;
use App\Http\Requests\PublicHoliday\UpdatePublicHolidayRequest;
use App\Http\Resources\PublicHolidayResource;
use App\Models\PublicHoliday;
use App\Services\PublicHolidayService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class PublicHolidayController extends Controller
{
    public function __construct(
        protected PublicHolidayService $publicHolidayService,
    ) {}

    public function index(IndexPublicHolidayRequest $request): JsonResponse
    {
        $this->authorize('viewAny', PublicHoliday::class);

        $paginator = $this->publicHolidayService->paginate($request->validated());
        $paginator->through(fn (PublicHoliday $publicHoliday) => PublicHolidayResource::make($publicHoliday)->resolve($request));

        return ApiResponse::paginated(
            paginator: $paginator,
            data: $paginator->items(),
            message: 'Public holidays fetched successfully.',
        );
    }

    public function store(StorePublicHolidayRequest $request): JsonResponse
    {
        $this->authorize('create', PublicHoliday::class);

        $publicHoliday = $this->publicHolidayService->create(
            data: $request->validated(),
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: PublicHolidayResource::make($publicHoliday)->resolve($request),
            message: 'Public holiday created successfully.',
            status: 201,
        );
    }

    public function update(UpdatePublicHolidayRequest $request, PublicHoliday $publicHoliday): JsonResponse
    {
        $this->authorize('update', $publicHoliday);

        $publicHoliday = $this->publicHolidayService->update(
            publicHoliday: $publicHoliday,
            data: $request->validated(),
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: PublicHolidayResource::make($publicHoliday)->resolve($request),
            message: 'Public holiday updated successfully.',
        );
    }

    public function destroy(PublicHoliday $publicHoliday): JsonResponse
    {
        $this->authorize('delete', $publicHoliday);

        $publicHoliday = $this->publicHolidayService->disable(
            publicHoliday: $publicHoliday,
            actor: request()->user(),
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
        );

        return ApiResponse::success(
            data: PublicHolidayResource::make($publicHoliday)->resolve(request()),
            message: 'Public holiday disabled successfully.',
        );
    }
}
