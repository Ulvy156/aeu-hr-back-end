<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class UserPermissionService
{
    public function __construct(
        protected AuditLogService $auditLogService,
    ) {}

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
    public function getDirectPermissionNames(User $user): array
    {
        return $user->getDirectPermissions()
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function getPermissionsViaRolesNames(User $user): array
    {
        return $user->getPermissionsViaRoles()
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
        return $this->getAllPermissionNames($user);
    }

    /**
     * @return array<int, string>
     */
    public function getAllPermissionNames(User $user): array
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
        return collect($this->getAllPermissionNames($user))
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
            'permissions' => $this->getAllPermissionNames($user),
            'grouped_permissions' => $this->getGroupedPermissions($user),
        ];
    }

    /**
     * @return array{user_id: int, direct_permissions: array<int, string>, role_permissions: array<int, string>, all_permissions: array<int, string>}
     */
    public function summary(User $user): array
    {
        return [
            'user_id' => $user->id,
            'direct_permissions' => $this->getDirectPermissionNames($user),
            'role_permissions' => $this->getPermissionsViaRolesNames($user),
            'all_permissions' => $this->getAllPermissionNames($user),
        ];
    }

    /**
     * @param  array<int, string>  $permissions
     * @return array{user_id: int, direct_permissions: array<int, string>, role_permissions: array<int, string>, all_permissions: array<int, string>}
     */
    public function syncDirectPermissions(
        User $user,
        array $permissions,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        return DB::transaction(function () use ($user, $permissions, $actor, $ipAddress, $userAgent): array {
            $oldValues = $this->summary($user);

            $user->syncPermissions($permissions);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $freshUser = $user->fresh();
            $newValues = $this->summary($freshUser);

            $this->auditLogService->log(
                action: 'assign_permissions',
                module: 'users',
                user: $actor,
                subject: $freshUser,
                oldValues: $oldValues,
                newValues: $newValues,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $newValues;
        });
    }

    /**
     * @return array{user_id: int, direct_permissions: array<int, string>, role_permissions: array<int, string>, all_permissions: array<int, string>}
     */
    public function addDirectPermission(
        User $user,
        string $permission,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        return DB::transaction(function () use ($user, $permission, $actor, $ipAddress, $userAgent): array {
            $oldValues = $this->summary($user);

            if (! $user->hasDirectPermission($permission)) {
                $user->givePermissionTo($permission);
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }

            $freshUser = $user->fresh();
            $newValues = $this->summary($freshUser);

            $this->auditLogService->log(
                action: 'add_permission',
                module: 'users',
                user: $actor,
                subject: $freshUser,
                oldValues: $oldValues,
                newValues: $newValues,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $newValues;
        });
    }

    /**
     * @return array{user_id: int, direct_permissions: array<int, string>, role_permissions: array<int, string>, all_permissions: array<int, string>}
     */
    public function removeDirectPermission(
        User $user,
        string $permission,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        return DB::transaction(function () use ($user, $permission, $actor, $ipAddress, $userAgent): array {
            $oldValues = $this->summary($user);

            if ($user->hasDirectPermission($permission)) {
                $user->revokePermissionTo($permission);
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }

            $freshUser = $user->fresh();
            $newValues = $this->summary($freshUser);

            $this->auditLogService->log(
                action: 'remove_permission',
                module: 'users',
                user: $actor,
                subject: $freshUser,
                oldValues: $oldValues,
                newValues: $newValues,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $newValues;
        });
    }
}
