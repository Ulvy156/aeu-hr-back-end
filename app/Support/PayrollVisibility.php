<?php

namespace App\Support;

use App\Enums\JobLevel;
use App\Enums\PayrollViewScope;
use App\Models\User;

class PayrollVisibility
{
    public function scope(User $viewer, string $viewAnyPermission, string $viewOwnPermission): ?PayrollViewScope
    {
        $viewer->loadMissing(['roles', 'employee.position', 'employee.department']);

        if ($this->seesCompanyWide($viewer) && $viewer->hasPermissionTo($viewAnyPermission)) {
            return PayrollViewScope::All;
        }

        if ($this->isNonHrDepartmentHead($viewer) && $viewer->hasPermissionTo($viewAnyPermission)) {
            return PayrollViewScope::Department;
        }

        if ($viewer->hasPermissionTo($viewAnyPermission)) {
            return PayrollViewScope::All;
        }

        if ($viewer->hasPermissionTo($viewOwnPermission)) {
            return PayrollViewScope::Own;
        }

        return null;
    }

    public function departmentId(User $viewer): ?int
    {
        $viewer->loadMissing('employee');

        return $viewer->employee?->department_id;
    }

    public function canAccessEmployeeDepartment(User $viewer, ?int $employeeDepartmentId, string $viewAnyPermission, string $viewOwnPermission, ?int $ownerEmployeeId = null): bool
    {
        $scope = $this->scope($viewer, $viewAnyPermission, $viewOwnPermission);

        return match ($scope) {
            PayrollViewScope::All => true,
            PayrollViewScope::Department => $employeeDepartmentId !== null
                && $employeeDepartmentId === $this->departmentId($viewer),
            PayrollViewScope::Own => $ownerEmployeeId !== null
                && $viewer->employee?->id === $ownerEmployeeId,
            default => false,
        };
    }

    protected function seesCompanyWide(User $viewer): bool
    {
        if ($viewer->hasAnyRole(['admin', 'hr', 'ceo', 'gm'])) {
            return true;
        }

        $jobLevel = $viewer->employee?->position?->job_level;

        if (in_array($jobLevel, [JobLevel::Gm, JobLevel::Ceo], true)) {
            return true;
        }

        return $jobLevel === JobLevel::Head && $this->isHrDepartment($viewer);
    }

    protected function isNonHrDepartmentHead(User $viewer): bool
    {
        $isHead = $viewer->hasRole('head')
            || $viewer->employee?->position?->job_level === JobLevel::Head;

        return $isHead && ! $this->isHrDepartment($viewer);
    }

    protected function isHrDepartment(User $viewer): bool
    {
        $departmentName = $viewer->employee?->department?->name;

        if ($departmentName === null) {
            return false;
        }

        return strcasecmp($departmentName, (string) config('hr.hr_department_name', 'HR & Admin')) === 0;
    }
}
