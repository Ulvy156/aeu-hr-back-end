<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserPermission\DeleteUserPermissionRequest;
use App\Http\Requests\UserPermission\StoreUserPermissionRequest;
use App\Http\Requests\UserPermission\SyncUserPermissionsRequest;
use App\Http\Resources\UserPermissionResource;
use App\Models\User;
use App\Services\UserPermissionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserPermissionController extends Controller
{
    public function __construct(
        protected UserPermissionService $userPermissionService,
    ) {}

    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return ApiResponse::success(
            data: UserPermissionResource::make($this->userPermissionService->summary($user))->resolve($request),
            message: 'User permissions fetched successfully.',
        );
    }

    public function sync(SyncUserPermissionsRequest $request, User $user): JsonResponse
    {
        $this->authorize('assignPermissions', $user);

        return ApiResponse::success(
            data: UserPermissionResource::make($this->userPermissionService->syncDirectPermissions(
                user: $user,
                permissions: $request->validated('permissions'),
                actor: $request->user(),
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            ))->resolve($request),
            message: 'User direct permissions updated successfully.',
        );
    }

    public function store(StoreUserPermissionRequest $request, User $user): JsonResponse
    {
        $this->authorize('assignPermissions', $user);

        return ApiResponse::success(
            data: UserPermissionResource::make($this->userPermissionService->addDirectPermission(
                user: $user,
                permission: $request->validated('permission'),
                actor: $request->user(),
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            ))->resolve($request),
            message: 'User direct permission added successfully.',
        );
    }

    public function destroy(DeleteUserPermissionRequest $request, User $user): JsonResponse
    {
        $this->authorize('assignPermissions', $user);

        return ApiResponse::success(
            data: UserPermissionResource::make($this->userPermissionService->removeDirectPermission(
                user: $user,
                permission: $request->validated('permission'),
                actor: $request->user(),
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            ))->resolve($request),
            message: 'User direct permission removed successfully.',
        );
    }
}
