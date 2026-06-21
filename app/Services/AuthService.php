<?php

namespace App\Services;

use App\Enums\EmploymentStatus;
use App\Enums\Status;
use App\Exceptions\ApiException;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /**
     * @return array{access_token: string, token_type: string, expires_in: int, user: User, refresh_token_plain: string}
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

        return DB::transaction(function () use ($user, $device): array {
            $user->tokens()->where('name', $device)->delete();

            $user->refreshTokens()
                ->where('device_name', $device)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            return $this->issueTokenPair($user, $device);
        });
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token_plain: string}
     */
    public function refresh(string $refreshTokenPlain): array
    {
        $hash = hash('sha256', $refreshTokenPlain);

        $refreshToken = RefreshToken::query()
            ->where('token', $hash)
            ->whereNull('revoked_at')
            ->first();

        if (! $refreshToken || $refreshToken->isExpired()) {
            throw ApiException::forbidden('Invalid or expired refresh token.');
        }

        $user = $refreshToken->user;

        if ($user->status !== Status::Active) {
            $refreshToken->update(['revoked_at' => now()]);

            throw ApiException::forbidden('Account is inactive.');
        }

        return DB::transaction(function () use ($refreshToken, $user): array {
            $refreshToken->update(['revoked_at' => now()]);

            $deviceName = $refreshToken->device_name;

            $user->tokens()->where('name', $deviceName)->delete();

            return $this->issueTokenPair($user, $deviceName);
        });
    }

    public function logout(User $user, ?string $refreshTokenPlain = null): void
    {
        $user->currentAccessToken()?->delete();

        if ($refreshTokenPlain) {
            $hash = hash('sha256', $refreshTokenPlain);
            RefreshToken::query()
                ->where('user_id', $user->id)
                ->where('token', $hash)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
        }
    }

    public function revokeAllRefreshTokens(User $user): void
    {
        $user->refreshTokens()
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function me(User $user): User
    {
        return $user->loadMissing(
            'roles:id,name',
            'employee:id,user_id,employee_id,full_name',
        );
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, user: User, refresh_token_plain: string}
     */
    protected function issueTokenPair(User $user, string $deviceName): array
    {
        $newToken = $user->createToken($deviceName);

        $refreshTokenPlain = Str::random(64);
        $user->refreshTokens()->create([
            'token' => hash('sha256', $refreshTokenPlain),
            'device_name' => $deviceName,
            'expires_at' => now()->addDays((int) config('hr.auth.refresh_token_expiration_days', 7)),
        ]);

        $expirationMinutes = (int) config('hr.auth.access_token_expiration_minutes', 15);

        return [
            'access_token' => $newToken->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => $expirationMinutes * 60,
            'user' => $user->loadMissing(
                'roles:id,name',
                'employee:id,user_id,employee_id,full_name',
            ),
            'refresh_token_plain' => $refreshTokenPlain,
        ];
    }
}
