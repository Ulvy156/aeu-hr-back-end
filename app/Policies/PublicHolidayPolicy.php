<?php

namespace App\Policies;

use App\Models\PublicHoliday;
use App\Models\User;

class PublicHolidayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('public_holidays.view_any');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('public_holidays.create');
    }

    public function update(User $user, PublicHoliday $publicHoliday): bool
    {
        return $user->hasPermissionTo('public_holidays.update');
    }

    public function delete(User $user, PublicHoliday $publicHoliday): bool
    {
        return $user->hasPermissionTo('public_holidays.delete');
    }
}
