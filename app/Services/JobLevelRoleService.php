<?php

namespace App\Services;

use App\Enums\JobLevel;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

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
        $position->loadMissing(['employees.user']);

        foreach ($position->employees as $employee) {
            if (! $employee->user) {
                continue;
            }

            // Use the position being synced so job_level changes apply immediately.
            $this->syncForUser($employee->user, $position->job_level);
        }
    }

    public function syncForUser(User $user, ?JobLevel $jobLevel): void
    {
        $user->loadMissing('roles');

        if ($user->hasAnyRole($this->protectedRoles())) {
            return;
        }

        if ($jobLevel !== null) {
            $roleName = $this->defaultRole($jobLevel);

            if ($roleName !== null && ! $user->hasRole($roleName)) {
                $user->syncRoles([$roleName]);
            }
        }

        $this->syncReportPermissions($user, $jobLevel);
    }

    protected function syncReportPermissions(User $user, ?JobLevel $jobLevel): void
    {
        $permissionsByLevel = (array) config('hr_permissions.report_job_level_permissions', []);
        $allReportPermissions = collect($permissionsByLevel)
            ->flatten()
            ->filter(fn ($permission) => is_string($permission) && $permission !== '')
            ->unique()
            ->values()
            ->all();

        if ($allReportPermissions === []) {
            return;
        }

        $granted = $jobLevel === null
            ? []
            : array_values(array_filter(
                (array) ($permissionsByLevel[$jobLevel->value] ?? []),
                fn ($permission) => is_string($permission) && $permission !== ''
            ));

        $toRevoke = array_values(array_intersect(
            array_diff($allReportPermissions, $granted),
            $user->getDirectPermissions()->pluck('name')->all()
        ));

        if ($toRevoke !== []) {
            $user->revokePermissionTo($toRevoke);
        }

        if ($granted !== []) {
            $user->givePermissionTo($granted);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
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
