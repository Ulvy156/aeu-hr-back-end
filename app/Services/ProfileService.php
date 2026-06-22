<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ProfileService
{
    public function __construct(
        protected UserPermissionService $userPermissionService,
        protected AuditLogService $auditLogService,
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

    /**
     * Change the authenticated user's own password.
     *
     * Verifies the current password, updates to the new password, and
     * revokes every other active Sanctum token (the current session stays
     * active).
     */
    public function changePassword(
        User $user,
        string $currentPassword,
        string $newPassword,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): void {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The provided password is incorrect.'],
            ]);
        }

        DB::transaction(function () use ($user, $newPassword, $ipAddress, $userAgent): void {
            $user->update(['password' => $newPassword]);

            $currentTokenId = $user->currentAccessToken()?->id;

            $user->tokens()
                ->when($currentTokenId, fn ($query) => $query->where('id', '!=', $currentTokenId))
                ->delete();

            $this->auditLogService->log(
                action: 'change_password',
                module: 'profile',
                user: $user,
                subject: $user,
                newValues: ['password' => '********'],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );
        });
    }
}
