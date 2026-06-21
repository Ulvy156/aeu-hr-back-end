<?php

namespace App\Services;

use App\Enums\EmploymentStatus;
use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\EmployeeUpgradeRequest;
use App\Models\EmploymentHistory;
use App\Models\User;
use App\Support\FileStorage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class EmployeeUpgradeRequestService
{
    public function __construct(
        protected EmployeeService $employeeService,
        protected EmploymentHistoryService $employmentHistoryService,
        protected AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, User $viewer): LengthAwarePaginator
    {
        $filters['employee_id'] = Employee::resolveId($filters['employee_id'] ?? null);
        $perPage = (int) ($filters['per_page'] ?? 15);

        return EmployeeUpgradeRequest::query()
            ->with(['employee:id,user_id,employee_id,full_name', 'requestedBy:id,name', 'reviewedBy:id,name'])
            ->when(
                ! $viewer->hasPermissionTo('employee_upgrade_requests.view_any'),
                fn (Builder $query) => $query->where('requested_by', $viewer->id)
            )
            ->when($filters['employee_id'] ?? null, fn (Builder $query, $employeeId) => $query->where('employee_id', $employeeId))
            ->when($filters['status'] ?? null, fn (Builder $query, $status) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>|null  $attachmentFiles
     */
    public function create(array $data, ?array $attachmentFiles, User $actor, ?string $ipAddress = null, ?string $userAgent = null): EmployeeUpgradeRequest
    {
        return DB::transaction(function () use ($data, $attachmentFiles, $actor, $ipAddress, $userAgent): EmployeeUpgradeRequest {
            $employee = Employee::query()->whereKey($data['employee_id'])->lockForUpdate()->firstOrFail();

            [$currentValues, $proposedValues] = $this->buildSnapshots($employee, $data['proposed_values']);

            if ($proposedValues === []) {
                throw ApiException::unprocessable(
                    "At least one proposed value must differ from the employee's current data.",
                    ['proposed_values' => ["At least one proposed value must differ from the employee's current data."]],
                );
            }

            $attributes = [
                'employee_id' => $employee->id,
                'status' => 'pending',
                'current_values' => $currentValues,
                'proposed_values' => $proposedValues,
                'effective_date' => $data['effective_date'] ?? null,
                'requested_by' => $actor->id,
            ];

            if (! empty($attachmentFiles)) {
                $attributes['attachments'] = $this->storeAttachments($attachmentFiles);
            }

            $upgradeRequest = EmployeeUpgradeRequest::query()->create($attributes);
            $upgradeRequest->load(['employee:id,user_id,employee_id,full_name', 'requestedBy:id,name']);

            $this->auditLogService->log(
                action: 'create',
                module: 'employee_upgrade_requests',
                user: $actor,
                subject: $upgradeRequest,
                newValues: $this->auditAttributes($upgradeRequest),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $upgradeRequest;
        });
    }

    public function approve(EmployeeUpgradeRequest $upgradeRequest, User $actor, ?string $ipAddress = null, ?string $userAgent = null): EmployeeUpgradeRequest
    {
        return DB::transaction(function () use ($upgradeRequest, $actor, $ipAddress, $userAgent): EmployeeUpgradeRequest {
            $upgradeRequest = EmployeeUpgradeRequest::query()->whereKey($upgradeRequest->id)->lockForUpdate()->firstOrFail();

            if ($upgradeRequest->status !== 'pending') {
                throw ApiException::unprocessable('Only pending upgrade requests can be approved.');
            }

            $employee = Employee::query()
                ->with(['user:id,name,email,status', 'department', 'position', 'manager'])
                ->whereKey($upgradeRequest->employee_id)
                ->lockForUpdate()
                ->firstOrFail();

            $oldAttributes = [];
            foreach (EmploymentHistory::TRACKED_FIELDS as $field) {
                $oldAttributes[$field] = $employee->getAttribute($field);
            }

            $oldDepartment = $employee->department;
            $oldPosition = $employee->position;
            $oldManager = $employee->manager;
            $oldEmployeeSnapshot = $this->employeeAuditSnapshot($employee);

            $changes = collect($upgradeRequest->proposed_values)
                ->only([...EmployeeUpgradeRequest::UPGRADABLE_FIELDS, 'last_working_date'])
                ->all();

            $this->employeeService->applyEmploymentFieldChanges($employee, $changes);
            $employee = $employee->fresh(['user:id,name,email,status', 'department', 'position', 'manager']);

            $this->employmentHistoryService->recordChanges(
                employee: $employee,
                oldAttributes: $oldAttributes,
                oldDepartment: $oldDepartment,
                oldPosition: $oldPosition,
                oldManager: $oldManager,
                actor: $actor,
                effectiveDate: $upgradeRequest->effective_date?->toDateString(),
            );

            $upgradeRequest->update([
                'status' => 'approved',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ]);

            $upgradeRequest->load(['employee:id,user_id,employee_id,full_name', 'requestedBy:id,name', 'reviewedBy:id,name']);

            $this->auditLogService->log(
                action: 'approve',
                module: 'employee_upgrade_requests',
                user: $actor,
                subject: $upgradeRequest,
                newValues: $this->auditAttributes($upgradeRequest),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            $this->auditLogService->log(
                action: 'update',
                module: 'employees',
                user: $actor,
                subject: $employee,
                oldValues: $oldEmployeeSnapshot,
                newValues: $this->employeeAuditSnapshot($employee),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $upgradeRequest;
        });
    }

    public function reject(EmployeeUpgradeRequest $upgradeRequest, User $actor, string $rejectionReason, ?string $ipAddress = null, ?string $userAgent = null): EmployeeUpgradeRequest
    {
        return DB::transaction(function () use ($upgradeRequest, $actor, $rejectionReason, $ipAddress, $userAgent): EmployeeUpgradeRequest {
            $upgradeRequest = EmployeeUpgradeRequest::query()->whereKey($upgradeRequest->id)->lockForUpdate()->firstOrFail();

            if ($upgradeRequest->status !== 'pending') {
                throw ApiException::unprocessable('Only pending upgrade requests can be rejected.');
            }

            $upgradeRequest->update([
                'status' => 'rejected',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'rejection_reason' => $rejectionReason,
            ]);

            $upgradeRequest->load(['employee:id,user_id,employee_id,full_name', 'requestedBy:id,name', 'reviewedBy:id,name']);

            $this->auditLogService->log(
                action: 'reject',
                module: 'employee_upgrade_requests',
                user: $actor,
                subject: $upgradeRequest,
                newValues: $this->auditAttributes($upgradeRequest),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $upgradeRequest;
        });
    }

    public function cancel(EmployeeUpgradeRequest $upgradeRequest, User $actor, ?string $ipAddress = null, ?string $userAgent = null): EmployeeUpgradeRequest
    {
        return DB::transaction(function () use ($upgradeRequest, $actor, $ipAddress, $userAgent): EmployeeUpgradeRequest {
            $upgradeRequest = EmployeeUpgradeRequest::query()->whereKey($upgradeRequest->id)->lockForUpdate()->firstOrFail();

            if ($upgradeRequest->status !== 'pending') {
                throw ApiException::unprocessable('Only pending upgrade requests can be cancelled.');
            }

            $upgradeRequest->update([
                'status' => 'cancelled',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ]);

            $upgradeRequest->load(['employee:id,user_id,employee_id,full_name', 'requestedBy:id,name', 'reviewedBy:id,name']);

            $this->auditLogService->log(
                action: 'cancel',
                module: 'employee_upgrade_requests',
                user: $actor,
                subject: $upgradeRequest,
                newValues: $this->auditAttributes($upgradeRequest),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $upgradeRequest;
        });
    }

    /**
     * Compare the proposed values against the employee's current attributes and
     * keep only the keys that actually differ, mirroring the normalization rules
     * used by EmploymentHistoryService::hasChanged().
     *
     * @param  array<string, mixed>  $proposedValues
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    protected function buildSnapshots(Employee $employee, array $proposedValues): array
    {
        $current = [];
        $proposed = [];

        foreach (EmployeeUpgradeRequest::UPGRADABLE_FIELDS as $field) {
            if (! array_key_exists($field, $proposedValues)) {
                continue;
            }

            $currentValue = $employee->getAttribute($field);
            $proposedValue = $this->normalizeFieldValue($field, $proposedValues[$field]);

            if (! $this->valuesDiffer($field, $currentValue, $proposedValue)) {
                continue;
            }

            $current[$field] = $this->normalizeFieldValue($field, $currentValue);
            $proposed[$field] = $proposedValue;
        }

        if (array_key_exists('employment_status', $proposed)) {
            $current['last_working_date'] = $employee->last_working_date?->toDateString();
            $proposed['last_working_date'] = $proposedValues['last_working_date'] ?? null;
        }

        return [$current, $proposed];
    }

    protected function normalizeFieldValue(string $field, mixed $value): mixed
    {
        return match ($field) {
            'department_id', 'position_id', 'manager_id' => $value === null ? null : (int) $value,
            'base_salary' => $value === null ? null : number_format((float) $value, 2, '.', ''),
            'employment_status' => $value instanceof EmploymentStatus ? $value->value : $value,
            default => $value,
        };
    }

    protected function valuesDiffer(string $field, mixed $current, mixed $proposed): bool
    {
        return match ($field) {
            'department_id', 'position_id', 'manager_id' => $this->normalizeFieldValue($field, $current) !== $this->normalizeFieldValue($field, $proposed),
            'base_salary' => $this->normalizeFieldValue($field, $current) !== $this->normalizeFieldValue($field, $proposed),
            default => $current !== $proposed,
        };
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, array{path: string, name: string, size: int}>
     */
    protected function storeAttachments(array $files): array
    {
        return collect($files)
            ->map(fn (UploadedFile $file): array => [
                'path' => FileStorage::disk()->putFile('employee-upgrade-requests', $file),
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function auditAttributes(EmployeeUpgradeRequest $upgradeRequest): array
    {
        return [
            'employee_id' => $upgradeRequest->employee_id,
            'status' => $upgradeRequest->status,
            'current_values' => $upgradeRequest->current_values,
            'proposed_values' => $upgradeRequest->proposed_values,
            'effective_date' => $upgradeRequest->effective_date?->toDateString(),
            'attachments' => collect($upgradeRequest->attachments ?? [])->pluck('name')->all(),
            'rejection_reason' => $upgradeRequest->rejection_reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function employeeAuditSnapshot(Employee $employee): array
    {
        return [
            'department_id' => $employee->department_id,
            'position_id' => $employee->position_id,
            'manager_id' => $employee->manager_id,
            'base_salary' => (string) $employee->base_salary,
            'employment_status' => $employee->employment_status->value,
            'probation_end_date' => $employee->probation_end_date?->toDateString(),
            'last_working_date' => $employee->last_working_date?->toDateString(),
            'user_status' => $employee->user?->status?->value,
        ];
    }
}
