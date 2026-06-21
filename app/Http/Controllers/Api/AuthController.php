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
use Symfony\Component\HttpFoundation\Cookie;

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

        return ApiResponse::success(
            data: [
                'access_token' => $payload['access_token'],
                'token_type' => $payload['token_type'],
                'expires_in' => $payload['expires_in'],
                'user' => $this->authUserPayload($payload['user'], $request),
            ],
            message: 'Login successful.',
        )->withCookie($this->makeRefreshCookie($payload['refresh_token_plain']));
    }

    public function refresh(Request $request): JsonResponse
    {
        $refreshTokenPlain = $request->cookie(self::REFRESH_TOKEN_COOKIE);

        if (! $refreshTokenPlain) {
            return ApiResponse::error('Refresh token not found.', status: 401);
        }

        $payload = $this->authService->refresh($refreshTokenPlain);

        return ApiResponse::success(
            data: [
                'access_token' => $payload['access_token'],
                'token_type' => $payload['token_type'],
                'expires_in' => $payload['expires_in'],
            ],
            message: 'Token refreshed successfully.',
        )->withCookie($this->makeRefreshCookie($payload['refresh_token_plain']));
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

        $refreshTokenPlain = $request->cookie(self::REFRESH_TOKEN_COOKIE);
        $this->authService->logout($request->user(), $refreshTokenPlain);

        return ApiResponse::success(
            data: null,
            message: 'Logout successful.',
        )->withCookie($this->forgetRefreshCookie());
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

    protected function makeRefreshCookie(string $token): Cookie
    {
        $days = (int) config('hr.auth.refresh_token_expiration_days', 7);

        return Cookie::create(
            name: self::REFRESH_TOKEN_COOKIE,
            value: $token,
            expire: time() + ($days * 86400),
            path: '/',
            secure: (bool) config('hr.auth.refresh_cookie_secure', true),
            httpOnly: true,
            sameSite: Cookie::SAMESITE_NONE,
        );
    }

    protected function forgetRefreshCookie(): Cookie
    {
        return Cookie::create(
            name: self::REFRESH_TOKEN_COOKIE,
            value: '',
            expire: 1,
            path: '/',
            secure: (bool) config('hr.auth.refresh_cookie_secure', true),
            httpOnly: true,
            sameSite: Cookie::SAMESITE_NONE,
        );
    }
}
