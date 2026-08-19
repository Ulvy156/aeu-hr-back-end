<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Permission\UpdatePermissionDescriptionRequest;
use App\Http\Requests\User\SyncUserRolesRequest;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\RoleResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;

class RolePermissionController extends Controller
{
    public function __construct(
        protected UserService $userService,
    ) {}

    public function roles(): JsonResponse
    {
        return ApiResponse::success(
            data: $this->userService
                ->availableRoles()
                ->map(fn ($role) => RoleResource::make($role)->resolve(request()))
                ->all(),
            message: 'Roles fetched successfully.',
        );
    }

    public function permissions(): JsonResponse
    {
        return ApiResponse::success(
            data: $this->userService
                ->availablePermissions()
                ->map(fn ($permission) => PermissionResource::make($permission)->resolve(request()))
                ->all(),
            message: 'Permissions fetched successfully.',
        );
    }

    public function updatePermissionDescription(UpdatePermissionDescriptionRequest $request, Permission $permission): JsonResponse
    {
        $permission = $this->userService->updatePermissionDescription(
            permission: $permission,
            description: $request->validated('description'),
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: PermissionResource::make($permission)->resolve($request),
            message: 'Permission description updated successfully.',
        );
    }

    public function syncUserRoles(SyncUserRolesRequest $request, User $user): JsonResponse
    {
        $this->authorize('assignRoles', $user);

        $user = $this->userService->syncRoles(
            user: $user,
            roles: $request->validated('roles'),
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: UserResource::make($user)->resolve($request),
            message: 'User roles updated successfully.',
        );
    }
}
