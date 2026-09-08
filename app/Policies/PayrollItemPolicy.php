<?php

namespace App\Policies;

use App\Models\PayrollItem;
use App\Models\User;
use App\Support\PayrollVisibility;

class PayrollItemPolicy
{
    public function __construct(
        protected PayrollVisibility $payrollVisibility,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->payrollVisibility->scope($user, 'payslips.view_any', 'payslips.view_own') !== null;
    }

    public function view(User $user, PayrollItem $payrollItem): bool
    {
        $payrollItem->loadMissing(['employee', 'payrollBatch']);

        $allowed = $this->payrollVisibility->canAccessEmployeeDepartment(
            viewer: $user,
            employeeDepartmentId: $payrollItem->employee?->department_id,
            viewAnyPermission: 'payslips.view_any',
            viewOwnPermission: 'payslips.view_own',
            ownerEmployeeId: $payrollItem->employee_id,
        );

        if (! $allowed) {
            return false;
        }

        if ($user->hasPermissionTo('payslips.view_any')) {
            return true;
        }

        return $payrollItem->payrollBatch?->status === 'approved';
    }

    public function download(User $user, PayrollItem $payrollItem): bool
    {
        $payrollItem->loadMissing(['employee', 'payrollBatch']);

        $allowed = $this->payrollVisibility->canAccessEmployeeDepartment(
            viewer: $user,
            employeeDepartmentId: $payrollItem->employee?->department_id,
            viewAnyPermission: 'payslips.download_any',
            viewOwnPermission: 'payslips.download_own',
            ownerEmployeeId: $payrollItem->employee_id,
        );

        if (! $allowed) {
            return false;
        }

        if ($user->hasPermissionTo('payslips.download_any')) {
            return true;
        }

        return $payrollItem->payrollBatch?->status === 'approved';
    }
}
