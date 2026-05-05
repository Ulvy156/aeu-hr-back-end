<?php

namespace App\Policies;

use App\Models\PayrollItem;
use App\Models\User;

class PayrollItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('payslips.view_any') || $user->hasPermissionTo('payslips.view_own');
    }

    public function view(User $user, PayrollItem $payrollItem): bool
    {
        if ($user->hasPermissionTo('payslips.view_any')) {
            return true;
        }

        return $user->hasPermissionTo('payslips.view_own')
            && $payrollItem->payrollBatch?->status === 'approved'
            && $user->loadMissing('employee')->employee?->id === $payrollItem->employee_id;
    }

    public function download(User $user, PayrollItem $payrollItem): bool
    {
        if ($user->hasPermissionTo('payslips.download_any')) {
            return true;
        }

        return $user->hasPermissionTo('payslips.download_own')
            && $payrollItem->payrollBatch?->status === 'approved'
            && $user->loadMissing('employee')->employee?->id === $payrollItem->employee_id;
    }
}
