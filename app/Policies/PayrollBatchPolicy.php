<?php

namespace App\Policies;

use App\Enums\PayrollViewScope;
use App\Models\PayrollBatch;
use App\Models\User;
use App\Support\PayrollVisibility;
use Illuminate\Database\Eloquent\Builder;

class PayrollBatchPolicy
{
    public function __construct(
        protected PayrollVisibility $payrollVisibility,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->payrollVisibility->scope($user, 'payrolls.view_any', 'payrolls.view_own') !== null;
    }

    public function view(User $user, PayrollBatch $payrollBatch): bool
    {
        $scope = $this->payrollVisibility->scope($user, 'payrolls.view_any', 'payrolls.view_own');

        return match ($scope) {
            PayrollViewScope::All => true,
            PayrollViewScope::Department => $this->batchHasDepartmentItems($payrollBatch, $this->payrollVisibility->departmentId($user)),
            PayrollViewScope::Own => $payrollBatch->status === 'approved' && $this->ownsBatch($user, $payrollBatch),
            default => false,
        };
    }

    public function generate(User $user): bool
    {
        return $user->hasPermissionTo('payrolls.generate');
    }

    public function update(User $user, PayrollBatch $payrollBatch): bool
    {
        return $user->hasPermissionTo('payrolls.update');
    }

    public function submit(User $user, PayrollBatch $payrollBatch): bool
    {
        return $user->hasPermissionTo('payrolls.submit');
    }

    public function approve(User $user, PayrollBatch $payrollBatch): bool
    {
        return $user->hasPermissionTo('payrolls.approve');
    }

    public function reject(User $user, PayrollBatch $payrollBatch): bool
    {
        return $user->hasPermissionTo('payrolls.reject');
    }

    protected function batchHasDepartmentItems(PayrollBatch $payrollBatch, ?int $departmentId): bool
    {
        if (! $departmentId) {
            return false;
        }

        return $payrollBatch->items()
            ->whereHas('employee', fn (Builder $query) => $query->where('department_id', $departmentId))
            ->exists();
    }

    protected function ownsBatch(User $user, PayrollBatch $payrollBatch): bool
    {
        return PayrollBatch::query()
            ->whereKey($payrollBatch->getKey())
            ->whereHas('items.employee', fn (Builder $query) => $query->where('user_id', $user->getKey()))
            ->exists();
    }
}
