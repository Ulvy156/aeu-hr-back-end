<?php

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class LeavePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('leaves.view_any') || $user->hasPermissionTo('leaves.view_own');
    }

    public function view(User $user, LeaveRequest $leave): bool
    {
        if ($this->canViewAll($user)) {
            return true;
        }

        return $user->hasPermissionTo('leaves.view_own') && $this->ownsLeave($user, $leave);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('leaves.create');
    }

    public function approve(User $user, LeaveRequest $leave): bool
    {
        return $this->canApproveAsHr($user) || $this->canApproveAsCeo($user);
    }

    public function reject(User $user, LeaveRequest $leave): bool
    {
        return $this->canRejectAsHr($user) || $this->canRejectAsCeo($user);
    }

    public function cancel(User $user, LeaveRequest $leave): bool
    {
        return $user->hasPermissionTo('leaves.cancel')
            && $user->loadMissing('employee')->employee?->id === $leave->employee_id;
    }

    protected function canViewAll(User $user): bool
    {
        return $user->hasPermissionTo('leaves.view_any') || $user->hasPermissionTo('leaves.view');
    }

    protected function canApproveAsHr(User $user): bool
    {
        return $user->hasRole('hr')
            && ($user->hasPermissionTo('leaves.approve_hr') || $user->hasPermissionTo('leaves.approve'));
    }

    protected function canApproveAsCeo(User $user): bool
    {
        return $user->hasRole('ceo')
            && ($user->hasPermissionTo('leaves.approve_ceo') || $user->hasPermissionTo('leaves.approve'));
    }

    protected function canRejectAsHr(User $user): bool
    {
        return $user->hasRole('hr')
            && ($user->hasPermissionTo('leaves.reject_hr') || $user->hasPermissionTo('leaves.reject'));
    }

    protected function canRejectAsCeo(User $user): bool
    {
        return $user->hasRole('ceo')
            && ($user->hasPermissionTo('leaves.reject_ceo') || $user->hasPermissionTo('leaves.reject'));
    }

    protected function ownsLeave(User $user, LeaveRequest $leave): bool
    {
        return LeaveRequest::query()
            ->whereKey($leave->getKey())
            ->whereHas('employee', fn (Builder $query) => $query->where('user_id', $user->getKey()))
            ->exists();
    }
}
