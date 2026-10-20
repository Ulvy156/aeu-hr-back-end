<?php

namespace App\Support;

use App\Enums\PayrollViewScope;
use App\Models\User;

class PayrollVisibility
{
    public function scope(User $viewer, string $viewAnyPermission, string $viewOwnPermission): ?PayrollViewScope
    {
        $viewer->loadMissing(['roles', 'employee.position', 'employee.department']);

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
}
