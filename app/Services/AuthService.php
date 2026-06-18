<?php

namespace App\Services;

use App\Enums\EmploymentStatus;
use App\Enums\Status;
use App\Exceptions\ApiException;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /**
     * Authenticate a user and issue access + refresh tokens.
     *
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, user: User}
     */
    public function login(string $email, string $password, ?string $deviceName = null): array
    {
        $user = User::query()->where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->status !== Status::Active) {
            throw ValidationException::withMessages([
                'email' => ['This account is inactive.'],
            ]);
        }

        if (! $user->hasRole('admin')) {
            $employee = $user->employee;

            if (! $employee || ! in_array($employee->employment_status, [EmploymentStatus::FullTime, EmploymentStatus::Probation], true)) {
                throw ValidationException::withMessages([
                    'email' => ['Your account is not linked to an active employee profile. Please contact HR.'],
                ]);
            }
        }

        $device = $deviceName ?: 'api-token';

        return [
            ...$this->issueTokenPair($user, $device),
            'user' => $user->loadMissing(
                'roles:id,name',
                'employee:id,user_id,employee_id,full_name',
            ),
        ];
    }

    /**
     * Refresh the access token using a valid refresh token.
     *
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
     */
    public function refresh(string $refreshTokenPlain): array
    {
        $hashed = hash('sha256', $refreshTokenPlain);

        $refreshToken = RefreshToken::query()->where('token', $hashed)->first();

        if (! $refreshToken || ! $refreshToken->isValid()) {
            throw ApiException::forbidden('Invalid or expired refresh token.');
        }

        $user = $refreshToken->user;

        if ($user->status !== Status::Active) {
            $refreshToken->update(['revoked_at' => now()]);

            throw ApiException::forbidden('Account is inactive.');
        }

        // Revoke the old refresh token (rotation)
        $refreshToken->update(['revoked_at' => now()]);

        return $this->issueTokenPair($user, $refreshToken->device_name);
    }

    /**
     * Revoke the current access token and its associated refresh token.
     */
    public function logout(User $user, ?string $refreshTokenPlain = null): void
    {
        $user->currentAccessToken()?->delete();

        if ($refreshTokenPlain) {
            $hashed = hash('sha256', $refreshTokenPlain);
            RefreshToken::query()
                ->where('user_id', $user->id)
                ->where('token', $hashed)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
        }
    }

    /**
     * Revoke all refresh tokens for a user.
     */
    public function revokeAllRefreshTokens(User $user): void
    {
        $user->refreshTokens()
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /**
     * Load related auth data for the authenticated user.
     */
    public function me(User $user): User
    {
        return $user->loadMissing(
            'roles:id,name',
            'employee:id,user_id,employee_id,full_name',
        );
    }

    /**
     * Issue a new access token + refresh token pair.
     *
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
     */
    protected function issueTokenPair(User $user, string $deviceName): array
    {
        $accessToken = $user->createToken($deviceName)->plainTextToken;

        $plainRefreshToken = Str::random(64);

        RefreshToken::query()->create([
            'user_id' => $user->id,
            'token' => hash('sha256', $plainRefreshToken),
            'device_name' => $deviceName,
            'expires_at' => now()->addDays((int) config('hr.auth.refresh_token_expiration_days', 7)),
        ]);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $plainRefreshToken,
            'token_type' => 'Bearer',
            'expires_in' => (int) config('hr.auth.access_token_expiration_minutes', 15) * 60,
        ];
    }
}
