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
        $employee->loadMissing(['user', 'position']);

        if (! $employee->user) {
            return;
        }

        $this->syncForUser($employee->user, $employee->position?->job_level);
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

    /**
     * @return array<int, string>
     */
    protected function protectedRoles(): array
    {
        return array_values(array_filter((array) config('hr.job_levels.protected_roles', ['admin', 'hr', 'ceo'])));
    }

    protected function defaultRole(JobLevel $jobLevel): ?string
    {
        $roleName = config('hr.job_levels.default_roles.'.$jobLevel->value);

        return is_string($roleName) && $roleName !== '' ? $roleName : null;
    }
}
