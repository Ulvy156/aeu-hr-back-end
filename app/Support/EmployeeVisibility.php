<?php

namespace App\Support;

use App\Enums\EmployeeViewScope;
use App\Enums\JobLevel;
use App\Models\Employee;
use App\Models\User;

class EmployeeVisibility
{
    public function scope(User $viewer): ?EmployeeViewScope
    {
        $viewer->loadMissing(['roles', 'employee.position']);

        if (! $viewer->hasPermissionTo('employees.view_any') && ! $viewer->hasPermissionTo('employees.view')) {
            return null;
        }

        if ($viewer->hasAnyRole(['hr', 'ceo', 'admin'])) {
            return EmployeeViewScope::All;
        }

        $jobLevel = $viewer->employee?->position?->job_level;

        if ($jobLevel === JobLevel::Gm) {
            return EmployeeViewScope::All;
        }

        if ($jobLevel === JobLevel::Head) {
            return EmployeeViewScope::Department;
        }

        if ($viewer->hasPermissionTo('employees.view_any') || $viewer->hasPermissionTo('employees.view')) {
            return EmployeeViewScope::All;
        }

        return null;
    }

    public function departmentId(User $viewer): ?int
    {
        $viewer->loadMissing('employee');

        return $viewer->employee?->department_id;
    }

    public function sameDepartment(User $viewer, Employee $employee): bool
    {
        $viewerDepartmentId = $this->departmentId($viewer);

        return $viewerDepartmentId !== null
            && $employee->department_id !== null
            && (int) $viewerDepartmentId === (int) $employee->department_id;
    }

    public function canViewEmployee(User $viewer, Employee $employee): bool
    {
        if (! $viewer->hasPermissionTo('employees.view')) {
            return false;
        }

        return match ($this->scope($viewer)) {
            EmployeeViewScope::All => true,
            EmployeeViewScope::Department => $this->sameDepartment($viewer, $employee),
            default => false,
        };
    }

    public function canViewSalary(User $viewer, Employee $employee): bool
    {
        if (! $this->canViewEmployee($viewer, $employee)) {
            return false;
        }

        if ($viewer->hasPermissionTo('employees.update_salary')) {
            return true;
        }

        if (! $viewer->hasPermissionTo('employees.view_salary')) {
            return false;
        }

        return match ($this->scope($viewer)) {
            EmployeeViewScope::All => true,
            EmployeeViewScope::Department => $this->sameDepartment($viewer, $employee),
            default => false,
        };
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Employee>  $query
     */
    public function constrainQuery(\Illuminate\Database\Eloquent\Builder $query, User $viewer): void
    {
        if ($this->scope($viewer) !== EmployeeViewScope::Department) {
            return;
        }

        $departmentId = $this->departmentId($viewer);

        if ($departmentId === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where($query->qualifyColumn('department_id'), $departmentId);
    }
}
