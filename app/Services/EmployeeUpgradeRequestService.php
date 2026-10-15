<?php

namespace App\Services;

use App\Enums\EmploymentStatus;
use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\EmployeeUpgradeRequest;
use App\Models\EmploymentHistory;
use App\Models\Position;
use App\Models\User;
use App\Support\FileStorage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class EmployeeUpgradeRequestService
{
    private const TRANSIENT_APPLICATION_ERROR = 'Application failed. Check server logs and retry.';

    public function __construct(
        protected EmployeeService $employeeService,
        protected EmploymentHistoryService $employmentHistoryService,
        protected AuditLogService $auditLogService,
        protected SalaryHistoryService $salaryHistoryService,
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
            $this->assertNextMonth($data['effective_date'] ?? null);

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

    public function assertNextMonth(?string $date): void
    {
        if ($date !== now('Asia/Phnom_Penh')->startOfMonth()->addMonth()->toDateString()) {
            throw ApiException::unprocessable('The effective month must be next month. Cancel an expired request and submit a new one.', [
                'effective_date' => ['Select next month as the effective month.'],
            ]);
        }
    }

    public function approve(EmployeeUpgradeRequest $upgradeRequest, User $actor, ?string $ipAddress = null, ?string $userAgent = null): EmployeeUpgradeRequest
    {
        return DB::transaction(function () use ($upgradeRequest, $actor, $ipAddress, $userAgent) {
            $employee = Employee::query()->whereKey($upgradeRequest->employee_id)->lockForUpdate()->firstOrFail();
            $upgradeRequest = EmployeeUpgradeRequest::query()->whereKey($upgradeRequest->id)->lockForUpdate()->firstOrFail();
            if ($upgradeRequest->status !== 'pending') {
                throw ApiException::unprocessable('Only pending upgrade requests can be approved.');
            }
            $this->assertNextMonth($upgradeRequest->effective_date?->toDateString());
            if (EmployeeUpgradeRequest::query()->where('employee_id', $employee->id)->where('status', 'approved')->whereNull('applied_at')->exists()) {
                throw ApiException::unprocessable('This employee already has an approved promotion waiting to apply. Wait for it to apply or cancel it first.');
            }
            $this->validateApplication($employee, $upgradeRequest->proposed_values);
            if (array_key_exists('base_salary', $upgradeRequest->proposed_values)) {
                $this->salaryHistoryService->assertPayrollAllowsChange($employee, $upgradeRequest->effective_date);
            }
            $upgradeRequest->update(['status' => 'approved', 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
            $this->auditLogService->log(action: 'approve', module: 'employee_upgrade_requests', user: $actor,
                subject: $upgradeRequest, newValues: $this->auditAttributes($upgradeRequest), ipAddress: $ipAddress, userAgent: $userAgent);

            return $upgradeRequest->load(['employee:id,user_id,employee_id,full_name', 'requestedBy:id,name', 'reviewedBy:id,name']);
        }, 3);
    }

    /** Apply one due request atomically; the employee lock is always acquired first. */
    public function apply(EmployeeUpgradeRequest $request): EmployeeUpgradeRequest
    {
        try {
            return DB::transaction(function () use ($request) {
                $employee = Employee::withTrashed()->with(['user', 'department', 'position', 'manager'])
                    ->whereKey($request->employee_id)->lockForUpdate()->firstOrFail();
                $upgradeRequest = EmployeeUpgradeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
                if ($upgradeRequest->status !== 'approved' || $upgradeRequest->applied_at || ! $upgradeRequest->effective_date
                    || $upgradeRequest->effective_date->toDateString() > now('Asia/Phnom_Penh')->toDateString()) {
                    return $upgradeRequest;
                }
                if ($employee->trashed()) {
                    throw ApiException::unprocessable('The employee was deleted. Cancel this promotion.');
                }
                $this->validateApplication($employee, $upgradeRequest->proposed_values);
                if (array_key_exists('base_salary', $upgradeRequest->proposed_values)) {
                    $this->salaryHistoryService->assertPayrollAllowsChange($employee, $upgradeRequest->effective_date);
                }
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
                    actor: $upgradeRequest->reviewedBy,
                    effectiveDate: $upgradeRequest->effective_date->toDateString(),
                    source: 'promotion',
                );

                $upgradeRequest->update(['applied_at' => now(), 'application_failed_at' => null, 'application_error' => null]);
                $this->auditLogService->log(action: 'apply', module: 'employee_upgrade_requests', user: $upgradeRequest->reviewedBy,
                    subject: $upgradeRequest, newValues: $this->auditAttributes($upgradeRequest));
                $this->auditLogService->log(action: 'update', module: 'employees', user: $upgradeRequest->reviewedBy,
                    subject: $employee, oldValues: $oldEmployeeSnapshot, newValues: $this->employeeAuditSnapshot($employee));

                return $upgradeRequest;
            }, 3);
        } catch (Throwable $exception) {
            // Technical exceptions stay in server logs; domain errors are safe to show to HR.
            EmployeeUpgradeRequest::query()->whereKey($request->id)->where('status', 'approved')->whereNull('applied_at')->update([
                'application_failed_at' => now(),
                'application_error' => $exception instanceof ApiException ? $exception->getMessage() : self::TRANSIENT_APPLICATION_ERROR,
            ]);
            Log::error('Promotion application failed', ['request_id' => $request->id, 'exception' => $exception]);
            throw $exception;
        }
    }

    /** Apply due requests before payroll calculation, or through the daily command. */
    public function applyDue(?array $employeeIds = null, bool $stopOnFailure = false): int
    {
        $count = 0;
        $query = EmployeeUpgradeRequest::query()->where('status', 'approved')->whereNull('applied_at')
            ->whereDate('effective_date', '<=', now('Asia/Phnom_Penh')->toDateString())
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->orderBy('employee_id')->orderBy('effective_date')->orderBy('id');
        foreach ($query->get() as $request) {
            try {
                if ($request->application_failed_at && $request->application_error !== self::TRANSIENT_APPLICATION_ERROR) {
                    throw ApiException::unprocessable($request->application_error.' Cancel this request and submit a replacement.');
                }
                $applied = $this->apply($request);
                if ($applied->applied_at) {
                    $count++;
                }
            } catch (Throwable $exception) {
                if ($stopOnFailure) {
                    throw ApiException::unprocessable("Cannot generate payroll: promotion #{$request->id} for employee #{$request->employee_id} could not apply. ".($exception instanceof ApiException ? $exception->getMessage() : 'Check server logs.'));
                }
            }
        }

        return $count;
    }

    protected function validateApplication(Employee $employee, array $changes): void
    {
        $validator = Validator::make($changes, [
            'department_id' => ['nullable', Rule::exists('departments', 'id')->whereNull('deleted_at')],
            'position_id' => ['nullable', Rule::exists('positions', 'id')->whereNull('deleted_at')],
            'manager_id' => ['nullable', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'base_salary' => ['numeric', 'min:0'],
            'employment_status' => [Rule::enum(EmploymentStatus::class)],
            'last_working_date' => ['nullable', 'date'],
        ]);
        if ($validator->fails()) {
            throw ApiException::unprocessable('The proposed employment details are no longer valid.', $validator->errors()->toArray());
        }
        $departmentId = array_key_exists('department_id', $changes) ? $changes['department_id'] : $employee->department_id;
        $positionId = array_key_exists('position_id', $changes) ? $changes['position_id'] : $employee->position_id;
        $position = $positionId ? Position::query()->find($positionId) : null;
        if ($position && $position->department_id && $departmentId && (int) $position->department_id !== (int) $departmentId) {
            throw ApiException::unprocessable('The selected position does not belong to the resulting department.');
        }
        $managerId = array_key_exists('manager_id', $changes) ? $changes['manager_id'] : $employee->manager_id;
        $visited = [$employee->id];
        while ($managerId !== null) {
            if (in_array((int) $managerId, $visited, true)) {
                throw ApiException::unprocessable('This change would create a circular reporting relationship.');
            }
            $visited[] = (int) $managerId;
            $manager = Employee::query()->find($managerId);
            if (! $manager) {
                throw ApiException::unprocessable('The selected manager is no longer available.');
            }
            $managerId = $manager->manager_id;
        }
        if (isset($changes['employment_status'])) {
            $inactive = in_array($changes['employment_status'], [EmploymentStatus::Resigned->value, EmploymentStatus::Terminated->value], true);
            if ($inactive && empty($changes['last_working_date'])) {
                throw ApiException::unprocessable('Resigned or terminated employees must have a last working date.');
            }
            if (! $inactive && ! empty($changes['last_working_date'])) {
                throw ApiException::unprocessable('Active employees must not have a last working date.');
            }
        }
    }

    public function reject(EmployeeUpgradeRequest $upgradeRequest, User $actor, string $rejectionReason, ?string $ipAddress = null, ?string $userAgent = null): EmployeeUpgradeRequest
    {
        return DB::transaction(function () use ($upgradeRequest, $actor, $rejectionReason, $ipAddress, $userAgent): EmployeeUpgradeRequest {
            Employee::withTrashed()->whereKey($upgradeRequest->employee_id)->lockForUpdate()->firstOrFail();
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
            Employee::withTrashed()->whereKey($upgradeRequest->employee_id)->lockForUpdate()->firstOrFail();
            $upgradeRequest = EmployeeUpgradeRequest::query()->whereKey($upgradeRequest->id)->lockForUpdate()->firstOrFail();

            $scheduled = $upgradeRequest->status === 'approved' && ! $upgradeRequest->applied_at;
            if ($upgradeRequest->status !== 'pending' && ! $scheduled) {
                throw ApiException::unprocessable('Only pending or approved unapplied requests can be cancelled.');
            }
            if ($scheduled && ! $actor->hasPermissionTo('employee_upgrade_requests.approve')) {
                throw ApiException::forbidden();
            }

            $upgradeRequest->update([
                'status' => 'cancelled',
                'reviewed_by' => $scheduled ? $upgradeRequest->reviewed_by : $actor->id,
                'reviewed_at' => $scheduled ? $upgradeRequest->reviewed_at : now(),
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
            'applied_at' => $upgradeRequest->applied_at?->toISOString(),
            'application_error' => $upgradeRequest->application_error,
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
            'intern_end_date' => $employee->intern_end_date?->toDateString(),
            'last_working_date' => $employee->last_working_date?->toDateString(),
            'user_status' => $employee->user?->status?->value,
        ];
    }
}
