<?php

namespace App\Services;

use App\Enums\EmploymentStatus;
use App\Enums\Status;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /**
     * @return array{access_token: string, token_type: string, expires_in: int, user: User}
     */
    public function login(string $email, string $password, string $fingerprint, ?string $deviceName = null): array
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

        $user->tokens()->where('device_id', $fingerprint)->delete();

        $newToken = $user->createToken($device);
        $newToken->accessToken->forceFill(['device_id' => $fingerprint])->save();

        $expirationDays = (int) config('hr.auth.access_token_expiration_days', 7);

        return [
            'access_token' => $newToken->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => $expirationDays * 24 * 60 * 60,
            'user' => $user->loadMissing(
                'roles:id,name',
                'employee:id,user_id,employee_id,full_name',
            ),
        ];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }

    public function me(User $user): User
    {
        return $user->loadMissing(
            'roles:id,name',
            'employee:id,user_id,employee_id,full_name',
        );
    }
}
