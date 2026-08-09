<?php

namespace App\Services;

use App\Enums\EmploymentStatus;
use App\Enums\Status;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /**
     * @return array{access_token: string, token_type: string, expires_in: int, user: User}
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

            if (! $employee || ! in_array($employee->employment_status, [EmploymentStatus::FullTime, EmploymentStatus::Probation, EmploymentStatus::Intern], true)) {
                throw ValidationException::withMessages([
                    'email' => ['Your account is not linked to an active employee profile. Please contact HR.'],
                ]);
            }
        }

        $device = $deviceName ?: 'api-token';

        return DB::transaction(function () use ($user, $device): array {
            $user->tokens()->where('name', $device)->delete();

            return $this->issueToken($user, $device);
        });
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

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, user: User}
     */
    protected function issueToken(User $user, string $deviceName): array
    {
        $newToken = $user->createToken($deviceName);

        $expirationMinutes = (int) config('sanctum.expiration', 43200);

        return [
            'access_token' => $newToken->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => $expirationMinutes * 60,
            'user' => $user->loadMissing(
                'roles:id,name',
                'employee:id,user_id,employee_id,full_name',
            ),
        ];
    }
}
