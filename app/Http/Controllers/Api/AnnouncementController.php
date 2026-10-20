<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Announcement\IndexAnnouncementRequest;
use App\Http\Requests\Announcement\StoreAnnouncementRequest;
use App\Http\Requests\Announcement\UpdateAnnouncementRequest;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\AnnouncementService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class AnnouncementController extends Controller
{
    public function __construct(
        protected AnnouncementService $announcementService,
    ) {}

    /** Minimal audience choices for announcement authors, without employee record access. */
    public function targetOptions(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasAnyPermission(['announcements.create', 'announcements.update']), 403);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'min:2', 'max:100'],
            'employee_ids' => ['nullable', 'array', 'max:100'],
            'employee_ids.*' => ['integer', 'min:1'],
        ]);
        $employees = collect();
        if (! empty($filters['q']) || ! empty($filters['employee_ids'])) {
            $query = Employee::query()->select(['id', 'employee_id', 'full_name'])->orderBy('full_name')->orderBy('id');
            if (! empty($filters['q'])) {
                $query->where(function ($query) use ($filters): void {
                    $query->where('full_name', 'like', '%'.$filters['q'].'%')
                        ->orWhere('employee_id', 'like', '%'.$filters['q'].'%');
                });
            } else {
                $query->whereIn('id', $filters['employee_ids']);
            }
            $employees = $query->limit(empty($filters['q']) ? 100 : 15)->get();
        }

        return ApiResponse::success(data: [
            'roles' => Role::query()->where('guard_name', config('hr_permissions.guard', 'web'))->orderBy('name')->get(['id', 'name']),
            'departments' => Department::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'employees' => $employees,
        ], message: 'Announcement audience options fetched successfully.');
    }

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

    public function publish(Announcement $announcement): JsonResponse
    {
        $this->authorize('publish', $announcement);

        $announcement = $this->announcementService->publish(
            announcement: $announcement,
            actor: request()->user(),
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
        );

        return ApiResponse::success(
            data: AnnouncementResource::make($announcement)->resolve(request()),
            message: 'Announcement published.',
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
