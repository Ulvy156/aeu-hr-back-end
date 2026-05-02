<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
            ->with(['user.roles:id,name', 'department', 'position'])
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('employee_id', 'like', '%'.$search.'%')
                        ->orWhere('full_name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->when($filters['department_id'] ?? null, fn (Builder $query, $departmentId) => $query->where('department_id', $departmentId))
            ->when($filters['position_id'] ?? null, fn (Builder $query, $positionId) => $query->where('position_id', $positionId))
            ->when($filters['employment_status'] ?? null, fn (Builder $query, $status) => $query->where('employment_status', $status))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(
        array $data,
        ?UploadedFile $profilePhoto,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Employee {
        return DB::transaction(function () use ($data, $profilePhoto, $actor, $ipAddress, $userAgent): Employee {
            $user = User::query()->create([
                'name' => $data['full_name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'status' => $this->userStatusFromEmploymentStatus($data['employment_status']),
            ]);
            $user->assignRole('employee');

            $employee = Employee::query()->create([
                ...$this->employeeAttributes($data),
                'user_id' => $user->id,
                'profile_photo' => $profilePhoto?->store('employee-profile-photos', 'public'),
            ]);

            $employee->load(['user.roles:id,name', 'department', 'position']);

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
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Employee {
        return DB::transaction(function () use ($employee, $data, $profilePhoto, $actor, $ipAddress, $userAgent): Employee {
            $employee->loadMissing(['user.roles:id,name', 'department', 'position']);
            $oldValues = $this->auditAttributes($employee);

            $employee->user->update([
                'name' => $data['full_name'],
                'email' => $data['email'],
                'status' => $this->userStatusFromEmploymentStatus($data['employment_status']),
            ]);

            if (! $employee->user->hasRole('employee')) {
                $employee->user->assignRole('employee');
            }

            $attributes = $this->employeeAttributes($data);

            if ($profilePhoto) {
                if ($employee->profile_photo) {
                    Storage::disk('public')->delete($employee->profile_photo);
                }

                $attributes['profile_photo'] = $profilePhoto->store('employee-profile-photos', 'public');
            }

            $employee->update($attributes);
            $employee = $employee->fresh(['user.roles:id,name', 'department', 'position']);

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
            $employee->loadMissing(['user.roles:id,name', 'department', 'position']);
            $oldValues = $this->auditAttributes($employee);

            $employee->user->update([
                'status' => 'inactive',
            ]);

            if ($employee->profile_photo) {
                Storage::disk('public')->delete($employee->profile_photo);
            }

            $employee->delete();

            $this->auditLogService->log(
                action: 'delete',
                module: 'employees',
                user: $actor,
                subject: $employee,
                oldValues: $oldValues,
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
            'employee_id' => $data['employee_id'],
            'full_name' => $data['full_name'],
            'gender' => $data['gender'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'phone_number' => $data['phone_number'] ?? null,
            'email' => $data['email'],
            'address' => $data['address'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'position_id' => $data['position_id'] ?? null,
            'join_date' => $data['join_date'],
            'last_working_date' => $data['last_working_date'] ?? null,
            'base_salary' => $data['base_salary'],
            'employment_status' => $data['employment_status'],
            'emergency_contact' => $data['emergency_contact'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function auditAttributes(Employee $employee): array
    {
        return [
            'employee_id' => $employee->employee_id,
            'full_name' => $employee->full_name,
            'email' => $employee->email,
            'department_id' => $employee->department_id,
            'position_id' => $employee->position_id,
            'join_date' => $employee->join_date?->toDateString(),
            'last_working_date' => $employee->last_working_date?->toDateString(),
            'base_salary' => (string) $employee->base_salary,
            'employment_status' => $employee->employment_status,
            'user_status' => $employee->user?->status,
        ];
    }

    protected function userStatusFromEmploymentStatus(string $employmentStatus): string
    {
        return $employmentStatus === 'active' ? 'active' : 'inactive';
    }
}
