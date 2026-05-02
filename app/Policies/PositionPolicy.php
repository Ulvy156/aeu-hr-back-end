<?php

namespace App\Policies;

use App\Models\Position;
use App\Models\User;

class PositionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('positions.view_any');
    }

    public function view(User $user, Position $position): bool
    {
        return $user->hasPermissionTo('positions.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('positions.create');
    }

    public function update(User $user, Position $position): bool
    {
        return $user->hasPermissionTo('positions.update');
    }

    public function delete(User $user, Position $position): bool
    {
        return $user->hasPermissionTo('positions.delete');
    }
}
