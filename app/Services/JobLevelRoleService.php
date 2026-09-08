<?php

namespace App\Services;

use App\Enums\JobLevel;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;

class JobLevelRoleService
{
    public function syncForEmployee(Employee $employee): void
    {
        $employee->loadMissing(['user', 'position.department', 'department']);

        if (! $employee->user) {
            return;
        }

        $this->syncForUser($employee->user, $employee->position?->job_level);
        $this->syncHrHeadDecisionPermissions($employee);
    }

    public function syncForPosition(Position $position): void
    {
        $position->loadMissing(['employees.user', 'employees.position.department', 'employees.department']);

        foreach ($position->employees as $employee) {
            $this->syncForEmployee($employee);
        }
    }

    public function syncForUser(User $user, ?JobLevel $jobLevel): void
    {
        if ($jobLevel === null) {
            return;
        }

        $user->loadMissing('roles');

        if ($user->hasAnyRole($this->protectedRoles())) {
            return;
        }

        $roleName = $this->defaultRole($jobLevel);

        if ($roleName === null || $user->hasRole($roleName)) {
            return;
        }

        $user->syncRoles([$roleName]);
    }

    protected function syncHrHeadDecisionPermissions(Employee $employee): void
    {
        $user = $employee->user;
        $permissions = $this->hrHeadPermissions();

        if ($user === null || $permissions === []) {
            return;
        }

        if ($this->isHrDepartmentHead($employee)) {
            $user->givePermissionTo($permissions);

            return;
        }

        $user->revokePermissionTo($permissions);
    }

    protected function isHrDepartmentHead(Employee $employee): bool
    {
        if ($employee->position?->job_level !== JobLevel::Head) {
            return false;
        }

        $departmentName = $employee->department?->name
            ?? $employee->position?->department?->name;

        if ($departmentName === null) {
            return false;
        }

        return strcasecmp($departmentName, (string) config('hr.hr_department_name', 'HR & Admin')) === 0;
    }

    /**
     * @return array<int, string>
     */
    protected function hrHeadPermissions(): array
    {
        return array_values(array_filter((array) config('hr.job_levels.hr_head_permissions', [
            'leaves.approve_hr',
            'leaves.reject_hr',
            'payrolls.generate',
            'payrolls.update',
            'payrolls.submit',
            'payrolls.approve',
            'payrolls.reject',
        ])));
    }

    /**
     * @return array<int, string>
     */
    protected function protectedRoles(): array
    {
        return array_values(array_filter((array) config('hr.job_levels.protected_roles', ['admin', 'hr'])));
    }

    protected function defaultRole(JobLevel $jobLevel): ?string
    {
        $roleName = config('hr.job_levels.default_roles.'.$jobLevel->value);

        return is_string($roleName) && $roleName !== '' ? $roleName : null;
    }
}
