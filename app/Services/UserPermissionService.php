<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

class UserPermissionService
{
    /**
     * @return array<int, string>
     */
    public function getRoleNames(User $user): array
    {
        return $user->loadMissing('roles:id,name')
            ->roles
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function getPermissionNames(User $user): array
    {
        return $user->getAllPermissions()
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function getGroupedPermissions(User $user): array
    {
        return collect($this->getPermissionNames($user))
            ->groupBy(fn (string $permission): string => Str::before($permission, '.'))
            ->sortKeys()
            ->map(fn ($permissions) => $permissions->values()->all())
            ->all();
    }

    /**
     * @return array{roles: array<int, string>, permissions: array<int, string>, grouped_permissions: array<string, array<int, string>>}
     */
    public function getPermissionSummary(User $user): array
    {
        return [
            'roles' => $this->getRoleNames($user),
            'permissions' => $this->getPermissionNames($user),
            'grouped_permissions' => $this->getGroupedPermissions($user),
        ];
    }
}
