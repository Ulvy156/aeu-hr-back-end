<?php

namespace Database\Seeders;

use App\Enums\JobLevel;
use App\Models\AnnouncementTarget;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
        DB::transaction(fn () => $this->seedRoles());
    }

    protected function seedRoles(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = (string) config('hr_permissions.guard', 'web');
        $groups = (array) config('hr_permissions.groups', []);
        $roles = (array) config('hr_permissions.roles', []);
        $permissionDescriptions = $this->permissionDescriptions($groups);
        $permissionModules = $this->permissionModules($groups);
        $allPermissions = $permissionDescriptions->keys();

        $this->removeRetiredAnnouncementPermissions($guard);

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

        $this->retireJobLevelRoles($guard);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function retireJobLevelRoles(string $guard): void
    {
        $retiredNames = ['gm', 'head', 'manager'];
        $supportedNames = array_keys(config('hr_permissions.roles'));
        $retiredRoles = Role::query()->where('guard_name', $guard)->whereIn('name', $retiredNames)->get();

        if ($retiredRoles->isEmpty()) {
            return;
        }

        // Keep targeted announcements limited to their existing recipients.
        foreach ($retiredRoles as $role) {
            $employeeIds = User::withTrashed()
                ->whereHas('roles', fn ($query) => $query->where('roles.id', $role->id))
                ->with('employeeWithTrashed')->get()->pluck('employeeWithTrashed.id')->filter();
            $targets = AnnouncementTarget::query()->where('target_type', 'role')->where('target_id', $role->id)->get();
            foreach ($targets as $target) {
                foreach ($employeeIds as $employeeId) {
                    AnnouncementTarget::query()->firstOrCreate([
                        'announcement_id' => $target->announcement_id,
                        'target_type' => 'employee',
                        'target_id' => $employeeId,
                    ]);
                }
                $target->delete();
            }
        }

        User::withTrashed()->whereHas('roles', fn ($query) => $query
            ->where('guard_name', $guard)->whereIn('name', $retiredNames))
            ->each(function (User $user) use ($supportedNames): void {
                if (! $user->hasAnyRole($supportedNames)) {
                    $user->assignRole('employee');
                }
            });

        $retiredRoles->each->delete();

        // Retire the automatic grants previously issued when an employee became a head.
        $headGrants = ['announcements.create', 'announcements.update', 'announcements.publish'];
        $hrHeadGrants = [
            ...$headGrants, 'payrolls.view_own', 'leaves.approve_hr', 'leaves.reject_hr',
            'payrolls.generate', 'payrolls.update', 'payrolls.submit', 'payrolls.delete',
            'payrolls.export', 'payrolls.approve', 'payrolls.reject',
        ];
        User::withTrashed()->whereHas('employeeWithTrashed.position', fn ($query) => $query
            ->where('job_level', JobLevel::Head->value))
            ->with('employeeWithTrashed.department')
            ->each(function (User $user) use ($headGrants, $hrHeadGrants): void {
                $isHr = strcasecmp($user->employeeWithTrashed?->department?->name ?? '', config('hr.hr_department_name')) === 0;
                $existingNames = $user->permissions()->pluck('name')->all();
                $user->revokePermissionTo(array_values(array_intersect($existingNames, $isHr ? $hrHeadGrants : $headGrants)));
            });
    }

    protected function removeRetiredAnnouncementPermissions(string $guard): void
    {
        $ids = Permission::query()
            ->where('guard_name', $guard)
            ->whereIn('name', [
                'announcements.submit',
                'announcements.cancel_submission',
                'announcements.approve',
            ])
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        Permission::query()->whereIn('id', $ids)->delete();
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
