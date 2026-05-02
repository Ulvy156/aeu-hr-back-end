<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\AuthUserResource;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\AuthService;
use App\Services\UserPermissionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        protected AuthService $authService,
        protected AuditLogService $auditLogService,
        protected UserPermissionService $userPermissionService,
    ) {}

    /**
     * Handle a login request and issue a Sanctum token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $deviceName = $request->validated('device_name') ?: 'api-token';

        $payload = $this->authService->login(
            email: $request->validated('email'),
            password: $request->validated('password'),
            deviceName: $deviceName,
        );

        $this->auditLogService->log(
            action: 'login',
            module: 'auth',
            user: $payload['user'],
            subject: $payload['user'],
            newValues: [
                'status' => 'logged_in',
                'device_name' => $deviceName,
            ],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: [
                'token' => $payload['token'],
                'token_type' => $payload['token_type'],
                'user' => $this->authUserPayload($payload['user'], $request),
            ],
            message: 'Login successful.',
        );
    }

    /**
     * Revoke the current Sanctum token.
     */
    public function logout(Request $request): JsonResponse
    {
        $this->auditLogService->log(
            action: 'logout',
            module: 'auth',
            user: $request->user(),
            subject: $request->user(),
            newValues: [
                'status' => 'logged_out',
            ],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        $this->authService->logout($request->user());

        return ApiResponse::success(
            data: null,
            message: 'Logout successful.',
        );
    }

    /**
     * Return the authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $this->authService->me($request->user());

        return ApiResponse::success(
            data: $this->authUserPayload($user, $request),
            message: 'Authenticated user fetched successfully.',
        );
    }

    /**
     * Build the authenticated user response shape for auth endpoints.
     *
     * @return array<string, mixed>
     */
    protected function authUserPayload(User $user, Request $request): array
    {
        $summary = $this->userPermissionService->getPermissionSummary($user);

        return (new AuthUserResource(
            $user,
            $summary['roles'],
            $summary['permissions'],
        ))->resolve($request);
    }
}
