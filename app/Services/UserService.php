<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserService
{
    public function __construct(
        protected AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 15);

        return User::query()
            ->with(['roles:id,name', 'employee.department', 'employee.position'])
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhereHas('employee', function (Builder $query) use ($search): void {
                            $query
                                ->where('full_name', 'like', '%'.$search.'%')
                                ->orWhereHas('department', fn (Builder $departmentQuery) => $departmentQuery->where('name', 'like', '%'.$search.'%'))
                                ->orWhereHas('position', fn (Builder $positionQuery) => $positionQuery->where('name', 'like', '%'.$search.'%'));
                        });
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderBy('name')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(
        array $data,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): User {
        return DB::transaction(function () use ($data, $actor, $ipAddress, $userAgent): User {
            $roleName = $this->singleRoleName($data['roles']);

            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'status' => $data['status'],
            ]);

            $user->syncRoles([$roleName]);
            $user->load(['roles:id,name', 'employee.department', 'employee.position']);

            $this->auditLogService->log(
                action: 'create',
                module: 'users',
                user: $actor,
                subject: $user,
                newValues: $this->auditAttributes($user),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $user;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(
        User $user,
        array $data,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): User {
        return DB::transaction(function () use ($user, $data, $actor, $ipAddress, $userAgent): User {
            $user->load(['roles:id,name', 'employee.department', 'employee.position']);
            $oldValues = $this->auditAttributes($user);
            $newRoleName = array_key_exists('roles', $data) ? $this->singleRoleName($data['roles']) : null;

            if ($actor->is($user) && $data['status'] === 'inactive') {
                throw ValidationException::withMessages([
                    'status' => ['You cannot deactivate your own account.'],
                ]);
            }

            $user->update([
                'name' => $data['name'],
                'email' => $data['email'],
                'status' => $data['status'],
            ]);

            if ($newRoleName !== null) {
                $user->syncRoles([$newRoleName]);
            }

            if ($data['status'] === 'inactive') {
                $user->tokens()->delete();
            }

            $user = $user->fresh(['roles:id,name', 'employee.department', 'employee.position']);

            $this->auditLogService->log(
                action: 'update',
                module: 'users',
                user: $actor,
                subject: $user,
                oldValues: $oldValues,
                newValues: $this->auditAttributes($user),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $user;
        });
    }

    public function deactivate(
        User $user,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): User {
        return DB::transaction(function () use ($user, $actor, $ipAddress, $userAgent): User {
            $user->load(['roles:id,name', 'employee.department', 'employee.position']);
            $oldValues = $this->auditAttributes($user);

            if ($actor->is($user)) {
                throw ValidationException::withMessages([
                    'user' => ['You cannot deactivate your own account.'],
                ]);
            }

            $user->update([
                'status' => 'inactive',
            ]);
            $user->tokens()->delete();

            $user = $user->fresh(['roles:id,name', 'employee.department', 'employee.position']);

            $this->auditLogService->log(
                action: 'deactivate',
                module: 'users',
                user: $actor,
                subject: $user,
                oldValues: $oldValues,
                newValues: $this->auditAttributes($user),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $user;
        });
    }

    /**
     * @param  array<int, string>  $roles
     */
    public function syncRoles(
        User $user,
        array $roles,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): User {
        return DB::transaction(function () use ($user, $roles, $actor, $ipAddress, $userAgent): User {
            $roleName = $this->singleRoleName($roles);
            $oldRoles = $user->getRoleNames()->sort()->values()->all();

            $user->syncRoles([$roleName]);
            $user = $user->fresh(['roles:id,name', 'employee.department', 'employee.position']);

            $this->auditLogService->log(
                action: 'assign_roles',
                module: 'users',
                user: $actor,
                subject: $user,
                oldValues: [
                    'roles' => $oldRoles,
                ],
                newValues: [
                    'roles' => $user->roles->pluck('name')->sort()->values()->all(),
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $user;
        });
    }

    /**
     * @return Collection<int, Role>
     */
    public function availableRoles(): Collection
    {
        return Role::query()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Permission>
     */
    public function availablePermissions(): Collection
    {
        return Permission::query()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    protected function auditAttributes(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'roles' => $user->relationLoaded('roles')
                ? $user->roles->pluck('name')->sort()->values()->all()
                : $user->getRoleNames()->sort()->values()->all(),
            'employee_id' => $user->employee?->employee_id,
            'employee_status' => $user->employee?->employment_status,
        ];
    }

    /**
     * @param  array<int, string>  $roles
     */
    protected function singleRoleName(array $roles): string
    {
        return (string) collect($roles)
            ->filter()
            ->values()
            ->first();
    }
}
