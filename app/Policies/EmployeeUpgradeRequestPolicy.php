<?php

namespace App\Policies;

use App\Models\EmployeeUpgradeRequest;
use App\Models\User;

class EmployeeUpgradeRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('employee_upgrade_requests.view_any') || $user->hasPermissionTo('employee_upgrade_requests.view');
    }

    public function view(User $user, EmployeeUpgradeRequest $upgradeRequest): bool
    {
        if ($user->hasPermissionTo('employee_upgrade_requests.view_any')) {
            return true;
        }

        return $user->hasPermissionTo('employee_upgrade_requests.view') && $upgradeRequest->requested_by === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('employee_upgrade_requests.create');
    }

    public function approve(User $user, EmployeeUpgradeRequest $upgradeRequest): bool
    {
        return $user->hasPermissionTo('employee_upgrade_requests.approve');
    }

    public function reject(User $user, EmployeeUpgradeRequest $upgradeRequest): bool
    {
        return $user->hasPermissionTo('employee_upgrade_requests.reject');
    }

    public function cancel(User $user, EmployeeUpgradeRequest $upgradeRequest): bool
    {
        return $user->hasPermissionTo('employee_upgrade_requests.cancel') && $upgradeRequest->requested_by === $user->id;
    }
}
