<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AnnouncementCategory\IndexAnnouncementCategoryRequest;
use App\Http\Requests\AnnouncementCategory\StoreAnnouncementCategoryRequest;
use App\Http\Requests\AnnouncementCategory\UpdateAnnouncementCategoryRequest;
use App\Http\Resources\AnnouncementCategoryResource;
use App\Models\AnnouncementCategory;
use App\Services\AnnouncementCategoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AnnouncementCategoryController extends Controller
{
    public function __construct(
        protected AnnouncementCategoryService $announcementCategoryService,
    ) {}

    public function index(IndexAnnouncementCategoryRequest $request): JsonResponse
    {
        $this->authorize('viewAny', AnnouncementCategory::class);

        $paginator = $this->announcementCategoryService->paginate($request->validated());
        $paginator->through(fn (AnnouncementCategory $category) => AnnouncementCategoryResource::make($category)->resolve($request));

        return ApiResponse::paginated(
            paginator: $paginator,
            data: $paginator->items(),
            message: 'Announcement categories fetched successfully.',
        );
    }

    public function store(StoreAnnouncementCategoryRequest $request): JsonResponse
    {
        $this->authorize('create', AnnouncementCategory::class);

        $category = $this->announcementCategoryService->create(
            data: $request->validated(),
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: AnnouncementCategoryResource::make($category)->resolve($request),
            message: 'Announcement category created successfully.',
            status: 201,
        );
    }

    public function show(AnnouncementCategory $announcementCategory): JsonResponse
    {
        $this->authorize('view', $announcementCategory);

        return ApiResponse::success(
            data: AnnouncementCategoryResource::make($announcementCategory)->resolve(request()),
            message: 'Announcement category fetched successfully.',
        );
    }

    public function update(UpdateAnnouncementCategoryRequest $request, AnnouncementCategory $announcementCategory): JsonResponse
    {
        $this->authorize('update', $announcementCategory);

        $category = $this->announcementCategoryService->update(
            category: $announcementCategory,
            data: $request->validated(),
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: AnnouncementCategoryResource::make($category)->resolve($request),
            message: 'Announcement category updated successfully.',
        );
    }

    public function destroy(AnnouncementCategory $announcementCategory): JsonResponse
    {
        $this->authorize('deactivate', $announcementCategory);

        $category = $this->announcementCategoryService->deactivate(
            category: $announcementCategory,
            actor: request()->user(),
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
        );

        return ApiResponse::success(
            data: AnnouncementCategoryResource::make($category)->resolve(request()),
            message: 'Announcement category deactivated successfully.',
        );
    }
}
