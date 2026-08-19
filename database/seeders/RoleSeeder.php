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
        $permissionDescriptions = $this->permissionDescriptions($groups);
        $permissionModules = $this->permissionModules($groups);
        $allPermissions = $permissionDescriptions->keys();

        $permissionDescriptions->each(function (?string $description, string $permissionName) use ($guard, $permissionModules): void {
            Permission::query()->updateOrCreate(
                ['name' => $permissionName, 'guard_name' => $guard],
                ['description' => $description, 'module' => $permissionModules->get($permissionName)],
            );
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($roles as $roleName => $definition) {
            $role = Role::findOrCreate($roleName, $guard);

            if ((bool) ($definition['all'] ?? false)) {
                $role->syncPermissions(
                    $allPermissions
                        ->reject(fn (string $permissionName) => in_array($permissionName, $definition['except'] ?? [], true))
                        ->values()
                        ->all()
                );

                continue;
            }

            $role->syncPermissions($this->permissionsForRole($definition, $groups)->all());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  array<string, array<string, string>>  $groups
     * @return Collection<string, string>
     */
    protected function permissionDescriptions(array $groups): Collection
    {
        return collect($groups)->collapse();
    }

    /**
     * @param  array<string, array<string, string>>  $groups
     * @return Collection<string, string>
     */
    protected function permissionModules(array $groups): Collection
    {
        return collect($groups)->flatMap(
            fn (array $permissions, string $groupName) => collect(array_keys($permissions))
                ->mapWithKeys(fn (string $permissionName) => [$permissionName => $groupName])
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, array<string, string>>  $groups
     */
    protected function permissionsForRole(array $definition, array $groups): Collection
    {
        $groupPermissions = collect($definition['groups'] ?? [])
            ->flatMap(fn (string $groupName) => array_keys($groups[$groupName] ?? []));

        $directPermissions = collect($definition['permissions'] ?? []);

        return $groupPermissions
            ->merge($directPermissions)
            ->filter()
            ->unique()
            ->values();
    }
}
