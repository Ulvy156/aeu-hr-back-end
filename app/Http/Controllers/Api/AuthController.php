<?php

namespace App\Http\Controllers\Api;

use App\Concerns\LogsAuditActivity;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\AuthUserResource;
use App\Services\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    use LogsAuditActivity;

    public function __construct(
        protected AuthService $authService,
    ) {}

    /**
     * Handle a login request and issue a Sanctum token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $payload = $this->authService->login(
            email: $request->validated('email'),
            password: $request->validated('password'),
            deviceName: $request->validated('device_name'),
        );

        $this->audit(
            action: 'login',
            module: 'auth',
            user: $payload['user'],
            subject: $payload['user'],
            newValues: [
                'status' => 'logged_in',
                'device_name' => $request->validated('device_name') ?: 'api-token',
            ],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: [
                'token' => $payload['token'],
                'token_type' => $payload['token_type'],
                'user' => AuthUserResource::make($payload['user'])->resolve($request),
            ],
            message: 'Login successful.',
        );
    }

    /**
     * Revoke the current Sanctum token.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        $this->audit(
            action: 'logout',
            module: 'auth',
            user: $request->user(),
            subject: $request->user(),
            oldValues: [
                'token_id' => $token?->id,
                'token_name' => $token?->name,
            ],
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
            data: AuthUserResource::make($user)->resolve($request),
            message: 'Authenticated user fetched successfully.',
        );
    }
}
