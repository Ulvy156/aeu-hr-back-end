<?php

namespace App\Policies;

use App\Models\AnnouncementCategory;
use App\Models\User;

class AnnouncementCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('announcement_categories.view');
    }

    public function view(User $user, AnnouncementCategory $category): bool
    {
        return $user->hasPermissionTo('announcement_categories.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('announcement_categories.create');
    }

    public function update(User $user, AnnouncementCategory $category): bool
    {
        return $user->hasPermissionTo('announcement_categories.update');
    }

    public function deactivate(User $user, AnnouncementCategory $category): bool
    {
        return $user->hasPermissionTo('announcement_categories.deactivate');
    }
}
