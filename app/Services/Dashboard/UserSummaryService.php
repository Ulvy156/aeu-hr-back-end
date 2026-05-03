<?php

namespace App\Services\Dashboard;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserSummaryService
{
    /**
     * @return array{
     *     total_users: int,
     *     active_users: int,
     *     inactive_users: int,
     *     users_by_role: array<string, int>
     * }
     */
    public function summary(): array
    {
        $totals = User::query()
            ->selectRaw('COUNT(*) as total_users')
            ->selectRaw("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_users")
            ->selectRaw("SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_users")
            ->first();

        $roleNames = array_keys((array) config('hr_permissions.roles', []));
        $guard = (string) config('hr_permissions.guard', 'web');
        $rolesTable = (string) config('permission.table_names.roles', 'roles');
        $modelHasRolesTable = (string) config('permission.table_names.model_has_roles', 'model_has_roles');
        $modelMorphKey = (string) config('permission.column_names.model_morph_key', 'model_id');

        $roleCounts = DB::table($rolesTable)
            ->leftJoin($modelHasRolesTable, function ($join) use ($rolesTable, $modelHasRolesTable): void {
                $join->on("{$rolesTable}.id", '=', "{$modelHasRolesTable}.role_id")
                    ->where("{$modelHasRolesTable}.model_type", User::class);
            })
            ->where("{$rolesTable}.guard_name", $guard)
            ->whereIn("{$rolesTable}.name", $roleNames)
            ->select(
                "{$rolesTable}.name",
                DB::raw("COUNT(DISTINCT {$modelHasRolesTable}.{$modelMorphKey}) as users_count"),
            )
            ->groupBy("{$rolesTable}.name")
            ->pluck('users_count', "{$rolesTable}.name");

        return [
            'total_users' => (int) ($totals?->total_users ?? 0),
            'active_users' => (int) ($totals?->active_users ?? 0),
            'inactive_users' => (int) ($totals?->inactive_users ?? 0),
            'users_by_role' => collect($roleNames)
                ->mapWithKeys(fn (string $roleName): array => [$roleName => (int) ($roleCounts[$roleName] ?? 0)])
                ->all(),
        ];
    }
}
