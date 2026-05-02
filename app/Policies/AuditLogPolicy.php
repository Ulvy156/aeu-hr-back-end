<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Activitylog\Models\Activity;

class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('audit_logs.view');
    }

    public function view(User $user, Activity $activity): bool
    {
        return $user->hasPermissionTo('audit_logs.view');
    }
}
