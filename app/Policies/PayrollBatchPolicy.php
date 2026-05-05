<?php

namespace App\Policies;

use App\Models\PayrollBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class PayrollBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('payrolls.view_any') || $user->hasPermissionTo('payrolls.view_own');
    }

    public function view(User $user, PayrollBatch $payrollBatch): bool
    {
        if ($user->hasPermissionTo('payrolls.view_any')) {
            return true;
        }

        return $user->hasPermissionTo('payrolls.view_own')
            && $payrollBatch->status === 'approved'
            && $this->ownsBatch($user, $payrollBatch);
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

    protected function ownsBatch(User $user, PayrollBatch $payrollBatch): bool
    {
        return PayrollBatch::query()
            ->whereKey($payrollBatch->getKey())
            ->whereHas('items.employee', fn (Builder $query) => $query->where('user_id', $user->getKey()))
            ->exists();
    }
}
