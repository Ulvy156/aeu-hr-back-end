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
    protected const REFRESH_TOKEN_COOKIE = 'refresh_token';

    public function __construct(
        protected AuthService $authService,
        protected AuditLogService $auditLogService,
        protected UserPermissionService $userPermissionService,
    ) {}

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

        $response = ApiResponse::success(
            data: [
                'access_token' => $payload['access_token'],
                'token_type' => $payload['token_type'],
                'expires_in' => $payload['expires_in'],
                'user' => $this->authUserPayload($payload['user'], $request),
            ],
            message: 'Login successful.',
        );

        return $this->attachRefreshTokenCookie($response, $payload['refresh_token']);
    }

    public function refresh(Request $request): JsonResponse
    {
        $refreshToken = $request->cookie(self::REFRESH_TOKEN_COOKIE);

        if (! $refreshToken) {
            return ApiResponse::error('Refresh token not found.', status: 401);
        }

        $payload = $this->authService->refresh($refreshToken);

        $response = ApiResponse::success(
            data: [
                'access_token' => $payload['access_token'],
                'token_type' => $payload['token_type'],
                'expires_in' => $payload['expires_in'],
            ],
            message: 'Token refreshed successfully.',
        );

        return $this->attachRefreshTokenCookie($response, $payload['refresh_token']);
    }

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

        $this->authService->logout(
            $request->user(),
            $request->cookie(self::REFRESH_TOKEN_COOKIE),
        );

        $response = ApiResponse::success(
            data: null,
            message: 'Logout successful.',
        );

        return $response->withCookie(cookie()->forget(self::REFRESH_TOKEN_COOKIE));
    }

    public function me(Request $request): JsonResponse
    {
        $user = $this->authService->me($request->user());

        return ApiResponse::success(
            data: $this->authUserPayload($user, $request),
            message: 'Authenticated user fetched successfully.',
        );
    }

    /**
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

    protected function attachRefreshTokenCookie(JsonResponse $response, string $refreshToken): JsonResponse
    {
        $expirationMinutes = (int) config('hr.auth.refresh_token_expiration_days', 7) * 24 * 60;

        $cookie = cookie(
            name: self::REFRESH_TOKEN_COOKIE,
            value: $refreshToken,
            minutes: $expirationMinutes,
            path: '/',
            secure: app()->isProduction(),
            httpOnly: true,
            sameSite: 'Lax',
        );

        return $response->withCookie($cookie);
    }
}
