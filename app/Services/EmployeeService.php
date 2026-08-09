<?php

namespace App\Services;

use App\Enums\EmploymentStatus;
use App\Enums\Status;
use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\User;
use App\Support\FileStorage;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmployeeService
{
    public function __construct(
        protected AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 15);

        return Employee::query()
            ->with(['user:id,name,email,status', 'department', 'position', 'manager'])
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $normalizedSearch = '%'.Str::lower($search).'%';

                $query->where(function (Builder $query) use ($normalizedSearch): void {
                    $query
                        ->whereRaw('LOWER(employee_id) LIKE ?', [$normalizedSearch])
                        ->orWhereRaw('LOWER(full_name) LIKE ?', [$normalizedSearch])
                        ->orWhereHas('user', fn (Builder $userQuery) => $userQuery->whereRaw('LOWER(email) LIKE ?', [$normalizedSearch]));
                });
            })
            ->when($filters['department_id'] ?? null, fn (Builder $query, $departmentId) => $query->where('department_id', $departmentId))
            ->when($filters['position_id'] ?? null, fn (Builder $query, $positionId) => $query->where('position_id', $positionId))
            ->when($filters['employment_status'] ?? null, fn (Builder $query, $status) => $query->where('employment_status', $status))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * @return Collection<int, array{employee_id: string, full_name: string, display: string}>
     */
    public function search(?string $query): Collection
    {
        $term = trim((string) $query);

        if ($term === '') {
            return collect();
        }

        $normalizedTerm = '%'.Str::lower($term).'%';

        return Employee::query()
            ->select(['employee_id', 'full_name', 'id'])
            ->where(function (Builder $query) use ($normalizedTerm): void {
                $query
                    ->whereRaw('LOWER(full_name) LIKE ?', [$normalizedTerm])
                    ->orWhereRaw('LOWER(employee_id) LIKE ?', [$normalizedTerm]);
            })
            ->orderBy('full_name')
            ->limit(15)
            ->get()
            ->map(fn (Employee $employee): array => [
                'employee_id' => $employee->id,
                'full_name' => $employee->full_name,
                'display' => "{$employee->employee_id} - {$employee->full_name}",
            ])
            ->values();
    }

    /**
     * Build the full reporting-line org chart as a tree of root employees
     * (those with no manager), each with a `children` relation populated
     * recursively.
     *
     * @return array<int, Employee>
     */
    public function tree(): array
    {
        $employees = Employee::query()
            ->select(['id', 'employee_id', 'full_name', 'manager_id', 'department_id', 'position_id', 'profile_photo'])
            ->with(['department:id,name', 'position:id,name'])
            ->orderBy('full_name')
            ->get();

        $childrenByManager = [];
        $roots = [];

        foreach ($employees as $employee) {
            if ($employee->manager_id === null) {
                $roots[] = $employee;
            } else {
                $childrenByManager[$employee->manager_id][] = $employee;
            }
        }

        $attachChildren = function (Employee $employee) use (&$attachChildren, $childrenByManager): void {
            $children = $childrenByManager[$employee->id] ?? [];

            foreach ($children as $child) {
                $attachChildren($child);
            }

            $employee->setRelation('children', collect($children));
        };

        foreach ($roots as $root) {
            $attachChildren($root);
        }

        return $roots;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(
        array $data,
        ?UploadedFile $profilePhoto,
        ?array $documentFiles,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Employee {
        return DB::transaction(function () use ($data, $profilePhoto, $documentFiles, $actor, $ipAddress, $userAgent): Employee {
            $employeeId = $this->generateEmployeeId();
            $user = User::query()
                ->with('employeeWithTrashed')
                ->lockForUpdate()
                ->findOrFail($data['user_id']);

            if ($user->employeeWithTrashed) {
                throw ApiException::unprocessable(
                    'The selected user already has an employee profile.',
                    ['user_id' => ['The selected user already has an employee profile.']],
                );
            }

            $user->update([
                'name' => $data['full_name'],
                'status' => $this->userStatusFromEmploymentStatus($data['employment_status']),
            ]);

            $employee = Employee::query()->create([
                ...$this->employeeAttributes($data),
                'employee_id' => $employeeId,
                'user_id' => $user->id,
                'profile_photo' => $profilePhoto
                    ? FileStorage::disk()->putFile('employee-profile-photos', $profilePhoto)
                    : null,
                'documents' => ! empty($documentFiles) ? $this->storeDocuments($documentFiles) : null,
            ]);

            $employee->load(['user:id,name,email,status', 'department', 'position', 'manager:id,employee_id,full_name']);

            $this->auditLogService->log(
                action: 'create',
                module: 'employees',
                user: $actor,
                subject: $employee,
                newValues: $this->auditAttributes($employee),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $employee;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(
        Employee $employee,
        array $data,
        ?UploadedFile $profilePhoto,
        ?array $documentFiles,
        ?array $removeDocuments,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Employee {
        return DB::transaction(function () use ($employee, $data, $profilePhoto, $documentFiles, $removeDocuments, $actor, $ipAddress, $userAgent): Employee {
            $employee->loadMissing(['user:id,name,email,status', 'department', 'position']);
            $oldValues = $this->auditAttributes($employee);

            $employee->user->update([
                'name' => $data['full_name'],
                'status' => $this->userStatusFromEmploymentStatus($data['employment_status']),
            ]);

            $attributes = $this->employeeAttributes($data);

            if ($profilePhoto) {
                if ($employee->profile_photo) {
                    FileStorage::disk()->delete($employee->profile_photo);
                }

                $attributes['profile_photo'] = FileStorage::disk()->putFile('employee-profile-photos', $profilePhoto);
            }

            $currentDocuments = $employee->documents ?? [];

            if (! empty($removeDocuments)) {
                $indicesToRemove = array_unique(array_map('intval', $removeDocuments));
                $documentsToDelete = [];

                foreach ($indicesToRemove as $index) {
                    if (isset($currentDocuments[$index])) {
                        $documentsToDelete[] = $currentDocuments[$index];
                        unset($currentDocuments[$index]);
                    }
                }

                $this->deleteDocumentFiles($documentsToDelete);
                $currentDocuments = array_values($currentDocuments);
            }

            if (! empty($documentFiles)) {
                $currentDocuments = array_merge($currentDocuments, $this->storeDocuments($documentFiles));
            }

            if (! empty($removeDocuments) || ! empty($documentFiles)) {
                $attributes['documents'] = ! empty($currentDocuments) ? $currentDocuments : null;
            }

            $employee->update($attributes);
            $employee = $employee->fresh(['user:id,name,email,status', 'department', 'position', 'manager:id,employee_id,full_name']);

            $this->auditLogService->log(
                action: 'update',
                module: 'employees',
                user: $actor,
                subject: $employee,
                oldValues: $oldValues,
                newValues: $this->auditAttributes($employee),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $employee;
        });
    }

    public function delete(
        Employee $employee,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): void {
        DB::transaction(function () use ($employee, $actor, $ipAddress, $userAgent): void {
            $employee->loadMissing(['user:id,name,email,status', 'department', 'position']);
            $oldValues = $this->auditAttributes($employee);

            if ($employee->user && ! $employee->user->trashed()) {
                $employee->user->update([
                    'status' => Status::Inactive->value,
                ]);
                $employee->user->tokens()->delete();
                $employee->user->delete();
            }

            if ($employee->profile_photo) {
                FileStorage::disk()->delete($employee->profile_photo);
            }

            if (! empty($employee->documents)) {
                $this->deleteDocumentFiles($employee->documents);
            }

            $employee->delete();

            $this->auditLogService->log(
                action: 'delete',
                module: 'employees',
                user: $actor,
                subject: $employee,
                oldValues: $oldValues,
                newValues: [
                    ...$oldValues,
                    'deleted_at' => $employee->deleted_at?->toISOString(),
                    'user_deleted_at' => $employee->user?->deleted_at?->toISOString(),
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function employeeAttributes(array $data): array
    {
        return [
            'full_name' => $data['full_name'],
            'gender' => $data['gender'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'phone_number' => $data['phone_number'] ?? null,
            'address' => $data['address'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'position_id' => $data['position_id'] ?? null,
            'manager_id' => $data['manager_id'] ?? null,
            'join_date' => $data['join_date'],
            'last_working_date' => $data['last_working_date'] ?? null,
            'base_salary' => $data['base_salary'],
            'employment_status' => $data['employment_status'],
            'probation_end_date' => $this->resolveProbationEndDate($data),
            'intern_end_date' => $this->resolveInternEndDate($data),
            'emergency_contact' => $data['emergency_contact'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function resolveProbationEndDate(array $data): ?string
    {
        if ($data['employment_status'] !== EmploymentStatus::Probation->value) {
            return null;
        }

        if (! empty($data['probation_end_date'])) {
            return $data['probation_end_date'];
        }

        return Carbon::parse($data['join_date'])
            ->addMonths((int) config('hr.employment.probation_period_months', 3))
            ->toDateString();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function resolveInternEndDate(array $data): ?string
    {
        if ($data['employment_status'] !== EmploymentStatus::Intern->value) {
            return null;
        }

        if (! empty($data['intern_end_date'])) {
            return $data['intern_end_date'];
        }

        return Carbon::parse($data['join_date'])
            ->addMonths((int) config('hr.employment.intern_period_months', 3))
            ->toDateString();
    }

    /**
     * Apply a partial set of employment-field changes (subset of department_id,
     * position_id, base_salary, employment_status, last_working_date), deriving
     * probation_end_date/intern_end_date and syncing the linked user's status
     * the same way a full profile update would.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed> the attributes actually applied
     */
    public function applyEmploymentFieldChanges(Employee $employee, array $changes): array
    {
        $attributes = $changes;

        if (array_key_exists('employment_status', $changes)) {
            $attributes['probation_end_date'] = $this->resolveProbationEndDate([
                'employment_status' => $changes['employment_status'],
                'join_date' => $employee->join_date?->toDateString(),
                'probation_end_date' => $changes['probation_end_date'] ?? null,
            ]);

            $attributes['intern_end_date'] = $this->resolveInternEndDate([
                'employment_status' => $changes['employment_status'],
                'join_date' => $employee->join_date?->toDateString(),
                'intern_end_date' => $changes['intern_end_date'] ?? null,
            ]);
        }

        $employee->update($attributes);

        if (array_key_exists('employment_status', $changes)) {
            $employee->user?->update([
                'status' => $this->userStatusFromEmploymentStatus($changes['employment_status']),
            ]);
        }

        return $attributes;
    }

    public function generateEmployeeId(): string
    {
        $latestEmployeeId = Employee::withTrashed()
            ->where('employee_id', 'like', 'EMP-%')
            ->lockForUpdate()
            ->orderByDesc('employee_id')
            ->value('employee_id');

        if (! $latestEmployeeId) {
            return 'EMP-00001';
        }

        $nextNumber = ((int) substr($latestEmployeeId, 4)) + 1;

        return 'EMP-'.str_pad((string) $nextNumber, 5, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string, mixed>
     */
    protected function auditAttributes(Employee $employee): array
    {
        return [
            'employee_id' => $employee->employee_id,
            'full_name' => $employee->full_name,
            'email' => $employee->user?->email,
            'department_id' => $employee->department_id,
            'position_id' => $employee->position_id,
            'manager_id' => $employee->manager_id,
            'join_date' => $employee->join_date?->toDateString(),
            'last_working_date' => $employee->last_working_date?->toDateString(),
            'base_salary' => (string) $employee->base_salary,
            'employment_status' => $employee->employment_status->value,
            'probation_end_date' => $employee->probation_end_date?->toDateString(),
            'intern_end_date' => $employee->intern_end_date?->toDateString(),
            'user_status' => $employee->user?->status?->value,
            'document_names' => collect($employee->documents ?? [])->pluck('name')->all(),
        ];
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, array{path: string, name: string, size: int}>
     */
    protected function storeDocuments(array $files): array
    {
        return collect($files)
            ->map(fn (UploadedFile $file): array => [
                'path' => FileStorage::disk()->putFile('employee-documents', $file),
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
            ])
            ->all();
    }

    /**
     * @param  array<int, array{path: string, name: string, size: int}>  $documents
     */
    protected function deleteDocumentFiles(array $documents): void
    {
        foreach ($documents as $document) {
            FileStorage::disk()->delete($document['path']);
        }
    }

    protected function userStatusFromEmploymentStatus(string $employmentStatus): string
    {
        return in_array($employmentStatus, [EmploymentStatus::FullTime->value, EmploymentStatus::Probation->value, EmploymentStatus::Intern->value], true)
            ? Status::Active->value
            : Status::Inactive->value;
    }
}
