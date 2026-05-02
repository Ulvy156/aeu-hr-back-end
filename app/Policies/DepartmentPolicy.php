<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;

class DepartmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('departments.view_any');
    }

    public function view(User $user, Department $department): bool
    {
        return $user->hasPermissionTo('departments.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('departments.create');
    }

    public function update(User $user, Department $department): bool
    {
        return $user->hasPermissionTo('departments.update');
    }

    public function delete(User $user, Department $department): bool
    {
        return $user->hasPermissionTo('departments.delete');
    }
}
