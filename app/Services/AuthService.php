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
     * Authenticate a user and issue a Sanctum token.
     *
     * @return array{token: string, token_type: string, user: User}
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

        $token = $user->createToken($deviceName ?: 'api-token')->plainTextToken;

        return [
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $user->loadMissing(
                'roles:id,name',
                'employee:id,user_id,employee_id,full_name',
            ),
        ];
    }

    /**
     * Revoke the current Sanctum token.
     */
    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
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
}
