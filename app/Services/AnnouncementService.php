<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Announcement;
use App\Models\AnnouncementTarget;
use App\Models\AnnouncementView;
use App\Models\Employee;
use App\Models\User;
use App\Support\FileStorage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class AnnouncementService
{
    public function __construct(
        protected AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateForManagement(array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 15);

        return Announcement::query()
            ->with(['category:id,name,status', 'creator:id,name'])
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $query->where(function (Builder $inner) use ($search) {
                    $inner->where('title', 'like', '%'.$search.'%')
                        ->orWhere('content', 'like', '%'.$search.'%');
                });
            })
            ->when($filters['category'] ?? null, fn (Builder $query, $category) => $query->where('category_id', $category))
            ->when($filters['priority'] ?? null, fn (Builder $query, $priority) => $query->where('priority', $priority))
            ->when($filters['status'] ?? null, fn (Builder $query, $status) => $query->where('status', $status))
            ->when($filters['created_by'] ?? null, fn (Builder $query, $createdBy) => $query->where('created_by', $createdBy))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateForEmployee(array $filters, Employee $employee, User $user): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 15);

        $query = Announcement::query()
            ->select('announcements.*')
            ->selectSub(
                AnnouncementView::query()
                    ->selectRaw('count(*)')
                    ->whereColumn('announcement_views.announcement_id', 'announcements.id')
                    ->where('announcement_views.employee_id', $employee->id),
                'is_viewed'
            )
            ->with([
                'category:id,name,status',
                'creator:id,name',
                'views' => fn ($query) => $query->where('employee_id', $employee->id),
            ])
            ->where('status', 'published')
            ->where(fn (Builder $query) => $this->applyTargetScope($query, $employee, $user))
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $query->where(function (Builder $inner) use ($search) {
                    $inner->where('title', 'like', '%'.$search.'%')
                        ->orWhere('content', 'like', '%'.$search.'%');
                });
            })
            ->when($filters['category'] ?? null, fn (Builder $query, $category) => $query->where('category_id', $category))
            ->when($filters['read_status'] ?? null, function (Builder $query, string $readStatus) use ($employee) {
                if ($readStatus === 'read') {
                    $query->whereHas('views', fn (Builder $inner) => $inner->where('employee_id', $employee->id));
                } elseif ($readStatus === 'unread') {
                    $query->whereDoesntHave('views', fn (Builder $inner) => $inner->where('employee_id', $employee->id));
                }
            })
            ->orderBy('is_viewed')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return $query->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $targets
     */
    public function create(
        array $data,
        array $targets,
        ?UploadedFile $attachment,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Announcement {
        return DB::transaction(function () use ($data, $targets, $attachment, $actor, $ipAddress, $userAgent): Announcement {
            $attributes = [
                'category_id' => $data['category_id'],
                'title' => $data['title'],
                'content' => $data['content'],
                'priority' => $data['priority'] ?? 'normal',
                'status' => 'draft',
                'created_by' => $actor->id,
            ];

            if ($attachment) {
                $attributes = array_merge($attributes, $this->storeAttachment($attachment));
            }

            $announcement = Announcement::query()->create($attributes);

            $this->syncTargets($announcement, $targets);

            $announcement = $this->loadRelations($announcement);

            $this->auditLogService->log(
                action: 'create',
                module: 'announcements',
                user: $actor,
                subject: $announcement,
                newValues: $this->auditAttributes($announcement),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $announcement;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $targets
     */
    public function update(
        Announcement $announcement,
        array $data,
        array $targets,
        ?UploadedFile $attachment,
        bool $removeAttachment,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Announcement {
        return DB::transaction(function () use ($announcement, $data, $targets, $attachment, $removeAttachment, $actor, $ipAddress, $userAgent): Announcement {
            $announcement = Announcement::query()->whereKey($announcement->id)->lockForUpdate()->firstOrFail();

            if (! in_array($announcement->status, ['draft', 'rejected'], true)) {
                throw ApiException::unprocessable('Only draft or rejected announcements can be edited.');
            }

            $oldValues = $this->auditAttributes($announcement);

            $attributes = [
                'category_id' => $data['category_id'],
                'title' => $data['title'],
                'content' => $data['content'],
                'priority' => $data['priority'] ?? $announcement->priority,
            ];

            if ($attachment) {
                if ($announcement->attachment_path) {
                    FileStorage::disk()->delete($announcement->attachment_path);
                }

                $attributes = array_merge($attributes, $this->storeAttachment($attachment));
            } elseif ($removeAttachment && $announcement->attachment_path) {
                FileStorage::disk()->delete($announcement->attachment_path);
                $attributes['attachment_path'] = null;
                $attributes['attachment_name'] = null;
                $attributes['attachment_size'] = null;
            }

            $announcement->update($attributes);

            $this->syncTargets($announcement, $targets);

            $announcement = $this->loadRelations($announcement->fresh());

            $this->auditLogService->log(
                action: 'update',
                module: 'announcements',
                user: $actor,
                subject: $announcement,
                oldValues: $oldValues,
                newValues: $this->auditAttributes($announcement),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $announcement;
        });
    }

    public function submit(
        Announcement $announcement,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Announcement {
        return DB::transaction(function () use ($announcement, $actor, $ipAddress, $userAgent): Announcement {
            $announcement = Announcement::query()->whereKey($announcement->id)->lockForUpdate()->firstOrFail();

            if (! in_array($announcement->status, ['draft', 'rejected'], true)) {
                throw ApiException::unprocessable('Only draft or rejected announcements can be submitted for approval.');
            }

            $oldValues = $this->auditAttributes($announcement);

            $announcement->forceFill([
                'status' => 'pending_approval',
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ])->save();

            $announcement = $this->loadRelations($announcement->fresh());

            $this->auditLogService->log(
                action: 'submit',
                module: 'announcements',
                user: $actor,
                subject: $announcement,
                oldValues: $oldValues,
                newValues: $this->auditAttributes($announcement),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $announcement;
        });
    }

    public function cancelSubmission(
        Announcement $announcement,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Announcement {
        return DB::transaction(function () use ($announcement, $actor, $ipAddress, $userAgent): Announcement {
            $announcement = Announcement::query()->whereKey($announcement->id)->lockForUpdate()->firstOrFail();

            if ($announcement->status !== 'pending_approval') {
                throw ApiException::unprocessable('Only announcements pending approval can have their submission cancelled.');
            }

            if ($announcement->created_by !== $actor->id) {
                throw ApiException::forbidden('Only the creator can cancel this submission.');
            }

            $oldValues = $this->auditAttributes($announcement);

            $announcement->forceFill([
                'status' => 'draft',
                'submitted_by' => null,
                'submitted_at' => null,
            ])->save();

            $announcement = $this->loadRelations($announcement->fresh());

            $this->auditLogService->log(
                action: 'cancel_submission',
                module: 'announcements',
                user: $actor,
                subject: $announcement,
                oldValues: $oldValues,
                newValues: $this->auditAttributes($announcement),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $announcement;
        });
    }

    public function approve(
        Announcement $announcement,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Announcement {
        return DB::transaction(function () use ($announcement, $actor, $ipAddress, $userAgent): Announcement {
            $announcement = Announcement::query()->whereKey($announcement->id)->lockForUpdate()->firstOrFail();

            if ($announcement->status !== 'pending_approval') {
                throw ApiException::unprocessable('Only announcements pending approval can be approved.');
            }

            if ($announcement->created_by === $actor->id) {
                throw ApiException::forbidden('You cannot approve your own announcement.');
            }

            $oldValues = $this->auditAttributes($announcement);

            $announcement->forceFill([
                'status' => 'published',
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ])->save();

            $announcement = $this->loadRelations($announcement->fresh());

            $this->auditLogService->log(
                action: 'approve',
                module: 'announcements',
                user: $actor,
                subject: $announcement,
                oldValues: $oldValues,
                newValues: $this->auditAttributes($announcement),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $announcement;
        });
    }

    public function reject(
        Announcement $announcement,
        string $rejectionReason,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Announcement {
        return DB::transaction(function () use ($announcement, $rejectionReason, $actor, $ipAddress, $userAgent): Announcement {
            $announcement = Announcement::query()->whereKey($announcement->id)->lockForUpdate()->firstOrFail();

            if ($announcement->status !== 'pending_approval') {
                throw ApiException::unprocessable('Only announcements pending approval can be rejected.');
            }

            if ($announcement->created_by === $actor->id) {
                throw ApiException::forbidden('You cannot reject your own announcement.');
            }

            $oldValues = $this->auditAttributes($announcement);

            $announcement->forceFill([
                'status' => 'rejected',
                'rejected_by' => $actor->id,
                'rejected_at' => now(),
                'rejection_reason' => $rejectionReason,
            ])->save();

            $announcement = $this->loadRelations($announcement->fresh());

            $this->auditLogService->log(
                action: 'reject',
                module: 'announcements',
                user: $actor,
                subject: $announcement,
                oldValues: $oldValues,
                newValues: $this->auditAttributes($announcement),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $announcement;
        });
    }

    public function archive(
        Announcement $announcement,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Announcement {
        return DB::transaction(function () use ($announcement, $actor, $ipAddress, $userAgent): Announcement {
            $announcement = Announcement::query()->whereKey($announcement->id)->lockForUpdate()->firstOrFail();

            if ($announcement->status !== 'published') {
                throw ApiException::unprocessable('Only published announcements can be archived.');
            }

            $oldValues = $this->auditAttributes($announcement);

            $announcement->update(['status' => 'archived']);

            $announcement = $this->loadRelations($announcement->fresh());

            $this->auditLogService->log(
                action: 'archive',
                module: 'announcements',
                user: $actor,
                subject: $announcement,
                oldValues: $oldValues,
                newValues: $this->auditAttributes($announcement),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $announcement;
        });
    }

    /**
     * Idempotently record that an employee has viewed an announcement.
     */
    public function markAsRead(Announcement $announcement, Employee $employee): void
    {
        AnnouncementView::query()->firstOrCreate(
            ['announcement_id' => $announcement->id, 'employee_id' => $employee->id],
            ['viewed_at' => now()],
        );
    }

    public function loadForManagement(Announcement $announcement): Announcement
    {
        return $this->loadRelations($announcement);
    }

    public function loadForEmployee(Announcement $announcement, Employee $employee): Announcement
    {
        return $announcement->load([
            'category:id,name,status',
            'creator:id,name',
            'targets',
            'views' => fn ($query) => $query->where('employee_id', $employee->id),
        ]);
    }

    /**
     * @return array{total_viewed: int, total_unread: int, viewed_employees: array<int, array<string, mixed>>, unread_employees: array<int, array<string, mixed>>}
     */
    public function readSummary(Announcement $announcement): array
    {
        $audience = $this->audienceQuery($announcement)->get(['id', 'employee_id', 'full_name']);
        $viewedEmployeeIds = $announcement->views()->pluck('employee_id')->all();

        $viewed = $audience->whereIn('id', $viewedEmployeeIds)->values();
        $unread = $audience->whereNotIn('id', $viewedEmployeeIds)->values();

        $mapEmployee = fn (Employee $employee): array => [
            'id' => $employee->id,
            'employee_id' => $employee->employee_id,
            'full_name' => $employee->full_name,
        ];

        return [
            'total_viewed' => $viewed->count(),
            'total_unread' => $unread->count(),
            'viewed_employees' => $viewed->map($mapEmployee)->all(),
            'unread_employees' => $unread->map($mapEmployee)->all(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $targets
     */
    protected function syncTargets(Announcement $announcement, array $targets): void
    {
        $announcement->targets()->delete();

        $rows = collect($targets)->map(fn (array $target) => [
            'announcement_id' => $announcement->id,
            'target_type' => $target['target_type'],
            'target_id' => $target['target_id'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        AnnouncementTarget::query()->insert($rows);
    }

    protected function applyTargetScope(Builder $query, Employee $employee, User $user): Builder
    {
        $roleIds = $user->roles()->pluck('roles.id')->all();
        $departmentId = $employee->department_id;
        $employeeId = $employee->id;

        return $query->whereHas('targets', function (Builder $targetQuery) use ($roleIds, $departmentId, $employeeId) {
            $targetQuery->where(function (Builder $group) use ($roleIds, $departmentId, $employeeId) {
                $group
                    ->where('target_type', 'all')
                    ->orWhere(function (Builder $inner) use ($employeeId) {
                        $inner->where('target_type', 'employee')->where('target_id', $employeeId);
                    });

                if ($departmentId) {
                    $group->orWhere(function (Builder $inner) use ($departmentId) {
                        $inner->where('target_type', 'department')->where('target_id', $departmentId);
                    });
                }

                if (! empty($roleIds)) {
                    $group->orWhere(function (Builder $inner) use ($roleIds) {
                        $inner->where('target_type', 'role')->whereIn('target_id', $roleIds);
                    });
                }
            });
        });
    }

    /**
     * Resolve the active employees targeted by an announcement.
     */
    protected function audienceQuery(Announcement $announcement): Builder
    {
        $targets = $announcement->targets()->get();

        $query = Employee::query()->whereIn('employment_status', ['active', 'probation']);

        if ($targets->contains(fn (AnnouncementTarget $target) => $target->target_type === 'all')) {
            return $query;
        }

        $roleIds = $targets->where('target_type', 'role')->pluck('target_id')->filter()->all();
        $departmentIds = $targets->where('target_type', 'department')->pluck('target_id')->filter()->all();
        $employeeIds = $targets->where('target_type', 'employee')->pluck('target_id')->filter()->all();

        return $query->where(function (Builder $inner) use ($roleIds, $departmentIds, $employeeIds) {
            $hasCondition = false;

            if (! empty($departmentIds)) {
                $inner->orWhereIn('department_id', $departmentIds);
                $hasCondition = true;
            }

            if (! empty($employeeIds)) {
                $inner->orWhereIn('id', $employeeIds);
                $hasCondition = true;
            }

            if (! empty($roleIds)) {
                $inner->orWhereHas('user', fn (Builder $userQuery) => $userQuery->whereHas(
                    'roles',
                    fn (Builder $roleQuery) => $roleQuery->whereIn('roles.id', $roleIds)
                ));
                $hasCondition = true;
            }

            if (! $hasCondition) {
                $inner->whereRaw('1 = 0');
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function storeAttachment(UploadedFile $file): array
    {
        return [
            'attachment_path' => FileStorage::disk()->putFile('announcements', $file),
            'attachment_name' => $file->getClientOriginalName(),
            'attachment_size' => $file->getSize(),
        ];
    }

    protected function loadRelations(Announcement $announcement): Announcement
    {
        return $announcement->load([
            'category:id,name,status',
            'creator:id,name',
            'submitter:id,name',
            'approver:id,name',
            'rejector:id,name',
            'targets',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function auditAttributes(Announcement $announcement): array
    {
        return [
            'category_id' => $announcement->category_id,
            'title' => $announcement->title,
            'priority' => $announcement->priority,
            'status' => $announcement->status,
            'attachment_name' => $announcement->attachment_name,
            'submitted_by' => $announcement->submitted_by,
            'submitted_at' => $announcement->submitted_at?->toISOString(),
            'approved_by' => $announcement->approved_by,
            'approved_at' => $announcement->approved_at?->toISOString(),
            'rejected_by' => $announcement->rejected_by,
            'rejected_at' => $announcement->rejected_at?->toISOString(),
            'rejection_reason' => $announcement->rejection_reason,
        ];
    }
}
