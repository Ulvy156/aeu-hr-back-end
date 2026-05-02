<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = (string) config('hr_permissions.guard', 'web');
        $groups = (array) config('hr_permissions.groups', []);
        $roles = (array) config('hr_permissions.roles', []);
        $allPermissions = $this->allPermissions($groups);

        $allPermissions->each(function (string $permissionName) use ($guard): void {
            Permission::findOrCreate($permissionName, $guard);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($roles as $roleName => $definition) {
            $role = Role::findOrCreate($roleName, $guard);

            if ((bool) ($definition['all'] ?? false)) {
                $role->syncPermissions($allPermissions->all());

                continue;
            }

            $role->syncPermissions($this->permissionsForRole($definition, $groups)->all());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  array<string, array<int, string>>  $groups
     */
    protected function allPermissions(array $groups): Collection
    {
        return collect($groups)
            ->flatten()
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, array<int, string>>  $groups
     */
    protected function permissionsForRole(array $definition, array $groups): Collection
    {
        $groupPermissions = collect($definition['groups'] ?? [])
            ->flatMap(fn (string $groupName) => $groups[$groupName] ?? []);

        $directPermissions = collect($definition['permissions'] ?? []);

        return $groupPermissions
            ->merge($directPermissions)
            ->filter()
            ->unique()
            ->values();
    }
}
