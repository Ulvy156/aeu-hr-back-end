<?php

namespace App\Policies;

use App\Models\CompanySetting;
use App\Models\User;

class CompanySettingPolicy
{
    public function view(User $user, CompanySetting $companySetting): bool
    {
        return $user->hasPermissionTo('company_settings.view');
    }

    public function update(User $user, CompanySetting $companySetting): bool
    {
        return $user->hasPermissionTo('company_settings.update');
    }
}
