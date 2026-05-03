<?php

namespace App\Services;

use App\Models\User;

class ProfileService
{
    public function __construct(
        protected UserPermissionService $userPermissionService,
    ) {}

    /**
     * @return array{user: User, roles: array<int, string>, permissions: array<int, string>}
     */
    public function show(User $user): array
    {
        $user->loadMissing([
            'employee.department:id,name,status',
            'employee.position:id,name,status',
            'roles:id,name',
        ]);

        $summary = $this->userPermissionService->getPermissionSummary($user);

        return [
            'user' => $user,
            'roles' => $summary['roles'],
            'permissions' => $summary['permissions'],
        ];
    }
}
