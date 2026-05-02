<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('users.view_any');
    }

    public function view(User $user, User $model): bool
    {
        return $user->hasPermissionTo('users.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('users.create');
    }

    public function update(User $user, User $model): bool
    {
        return $user->hasPermissionTo('users.update');
    }

    public function delete(User $user, User $model): bool
    {
        return $user->hasPermissionTo('users.delete');
    }

    public function resetPassword(User $user, User $model): bool
    {
        return $user->hasPermissionTo('users.reset_password');
    }

    public function assignRoles(User $user, User $model): bool
    {
        return $user->hasPermissionTo('users.assign_roles');
    }
}
