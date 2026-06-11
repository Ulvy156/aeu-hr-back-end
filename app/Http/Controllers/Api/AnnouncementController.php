<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Announcement\IndexAnnouncementRequest;
use App\Http\Requests\Announcement\RejectAnnouncementRequest;
use App\Http\Requests\Announcement\StoreAnnouncementRequest;
use App\Http\Requests\Announcement\UpdateAnnouncementRequest;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use App\Models\Employee;
use App\Models\User;
use App\Services\AnnouncementService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AnnouncementController extends Controller
{
    public function __construct(
        protected AnnouncementService $announcementService,
    ) {}

    public function index(IndexAnnouncementRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Announcement::class);

        $user = $request->user();

        if ($user->hasPermissionTo('announcements.view_draft')) {
            $paginator = $this->announcementService->paginateForManagement($request->validated());
        } else {
            $paginator = $this->announcementService->paginateForEmployee(
                filters: $request->validated(),
                employee: $this->employeeForUserOrFail($user),
                user: $user,
            );
        }

        $paginator->through(fn (Announcement $announcement) => AnnouncementResource::make($announcement)->resolve($request));

        return ApiResponse::paginated(
            paginator: $paginator,
            data: $paginator->items(),
            message: 'Announcements fetched successfully.',
        );
    }

    public function store(StoreAnnouncementRequest $request): JsonResponse
    {
        $this->authorize('create', Announcement::class);

        $announcement = $this->announcementService->create(
            data: $request->validated(),
            targets: $request->validated('targets'),
            attachment: $request->file('attachment'),
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: AnnouncementResource::make($announcement)->resolve($request),
            message: 'Announcement created successfully.',
            status: 201,
        );
    }

    public function show(Announcement $announcement): JsonResponse
    {
        $this->authorize('view', $announcement);

        $user = request()->user();

        if ($user->hasPermissionTo('announcements.view_draft')) {
            $announcement = $this->announcementService->loadForManagement($announcement);

            $data = AnnouncementResource::make($announcement)->resolve(request());
            $data['read_summary'] = $this->announcementService->readSummary($announcement);

            return ApiResponse::success(
                data: $data,
                message: 'Announcement fetched successfully.',
            );
        }

        $employee = $this->employeeForUserOrFail($user);
        $this->announcementService->markAsRead($announcement, $employee);
        $announcement = $this->announcementService->loadForEmployee($announcement, $employee);

        return ApiResponse::success(
            data: AnnouncementResource::make($announcement)->resolve(request()),
            message: 'Announcement fetched successfully.',
        );
    }

    public function update(UpdateAnnouncementRequest $request, Announcement $announcement): JsonResponse
    {
        $this->authorize('update', $announcement);

        $announcement = $this->announcementService->update(
            announcement: $announcement,
            data: $request->validated(),
            targets: $request->validated('targets'),
            attachment: $request->file('attachment'),
            removeAttachment: $request->boolean('remove_attachment'),
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: AnnouncementResource::make($announcement)->resolve($request),
            message: 'Announcement updated successfully.',
        );
    }

    public function submit(Announcement $announcement): JsonResponse
    {
        $this->authorize('submit', $announcement);

        $announcement = $this->announcementService->submit(
            announcement: $announcement,
            actor: request()->user(),
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
        );

        return ApiResponse::success(
            data: AnnouncementResource::make($announcement)->resolve(request()),
            message: 'Announcement submitted for approval.',
        );
    }

    public function cancelSubmission(Announcement $announcement): JsonResponse
    {
        $this->authorize('cancelSubmission', $announcement);

        $announcement = $this->announcementService->cancelSubmission(
            announcement: $announcement,
            actor: request()->user(),
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
        );

        return ApiResponse::success(
            data: AnnouncementResource::make($announcement)->resolve(request()),
            message: 'Announcement submission cancelled.',
        );
    }

    public function approve(Announcement $announcement): JsonResponse
    {
        $this->authorize('approve', $announcement);

        $announcement = $this->announcementService->approve(
            announcement: $announcement,
            actor: request()->user(),
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
        );

        return ApiResponse::success(
            data: AnnouncementResource::make($announcement)->resolve(request()),
            message: 'Announcement approved and published.',
        );
    }

    public function reject(RejectAnnouncementRequest $request, Announcement $announcement): JsonResponse
    {
        $this->authorize('reject', $announcement);

        $announcement = $this->announcementService->reject(
            announcement: $announcement,
            rejectionReason: $request->validated('rejection_reason'),
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: AnnouncementResource::make($announcement)->resolve($request),
            message: 'Announcement rejected.',
        );
    }

    public function archive(Announcement $announcement): JsonResponse
    {
        $this->authorize('archive', $announcement);

        $announcement = $this->announcementService->archive(
            announcement: $announcement,
            actor: request()->user(),
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
        );

        return ApiResponse::success(
            data: AnnouncementResource::make($announcement)->resolve(request()),
            message: 'Announcement archived.',
        );
    }

    public function read(Announcement $announcement): JsonResponse
    {
        $this->authorize('view', $announcement);

        $employee = $this->employeeForUserOrFail(request()->user());
        $this->announcementService->markAsRead($announcement, $employee);

        return ApiResponse::success(
            data: null,
            message: 'Announcement marked as read.',
        );
    }

    protected function employeeForUserOrFail(User $user): Employee
    {
        $employee = $user->loadMissing('employee')->employee;

        if (! $employee) {
            throw ApiException::forbidden('No employee profile is linked to this user account.');
        }

        return $employee;
    }
}
