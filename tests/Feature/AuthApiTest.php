<?php

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function deviceHeaders(array $overrides = []): array
{
    return array_merge([
        'User-Agent' => 'TestBrowser/1.0',
        'Accept-Language' => 'en-US',
        'X-Screen-Resolution' => '1920x1080',
        'X-Timezone' => 'Asia/Phnom_Penh',
        'X-Platform' => 'Windows',
    ], $overrides);
}

function loginWithDevice(mixed $testCase, array $payload = [], array $headers = [])
{
    $defaultPayload = [
        'email' => 'employee@example.com',
        'password' => 'secret-password',
        'device_name' => 'web-client',
    ];

    $mergedHeaders = deviceHeaders($headers);

    return $testCase->withHeaders($mergedHeaders)
        ->postJson('/api/login', array_merge($defaultPayload, $payload));
}

function createTokenWithFingerprint(User $user, array $headers = []): string
{
    $mergedHeaders = deviceHeaders($headers);

    $fingerprint = hash('sha256', implode('|', [
        $mergedHeaders['User-Agent'] ?? '',
        $mergedHeaders['Accept-Language'] ?? '',
        $mergedHeaders['X-Screen-Resolution'] ?? '',
        $mergedHeaders['X-Timezone'] ?? '',
        $mergedHeaders['X-Platform'] ?? '',
    ]));

    $newToken = $user->createToken('test-device');
    $newToken->accessToken->forceFill(['device_id' => $fingerprint])->save();

    return $newToken->plainTextToken;
}

test('a user can login and receive a sanctum token', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create([
        'email' => 'employee@example.com',
        'password' => 'secret-password',
    ]);

    $user->assignRole('employee');

    Employee::query()->create([
        'user_id' => $user->id,
        'employee_id' => 'EMP001',
        'full_name' => 'Employee User',
        'join_date' => now()->toDateString(),
        'employment_status' => 'full-time',
    ]);

    $response = loginWithDevice($this);

    $response
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Login successful.')
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.email', 'employee@example.com')
        ->assertJsonPath('data.user.roles.0', 'employee')
        ->assertJsonPath('data.user.employee.employee_id', 'EMP001');

    expect($response->json('data.access_token'))->toBeString()->not->toBeEmpty();
    expect($response->json('data.expires_in'))->toBeInt()->toBeGreaterThan(0);
    expect($user->tokens()->count())->toBe(1);
    expect($user->tokens()->first()->device_id)->not->toBeNull();
});

test('admin can login without an employee profile', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create([
        'email' => 'admin@example.com',
        'password' => 'secret-password',
    ]);

    $user->assignRole('admin');

    $response = loginWithDevice($this, ['email' => 'admin@example.com']);

    $response
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.email', 'admin@example.com');
});

test('non-admin user without an employee profile cannot login', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create([
        'email' => 'employee@example.com',
        'password' => 'secret-password',
    ]);

    $user->assignRole('employee');

    $response = loginWithDevice($this);

    $response
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors('email');
});

test('non-admin user with a probation employee profile can login', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create([
        'email' => 'probation@example.com',
        'password' => 'secret-password',
    ]);

    $user->assignRole('employee');

    Employee::query()->create([
        'user_id' => $user->id,
        'employee_id' => 'EMP002',
        'full_name' => 'Probation User',
        'join_date' => now()->toDateString(),
        'employment_status' => 'probation',
    ]);

    $response = loginWithDevice($this, ['email' => 'probation@example.com']);

    $response
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.employee.employee_id', 'EMP002');
});

test('non-admin user with an inactive employee profile cannot login', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create([
        'email' => 'employee@example.com',
        'password' => 'secret-password',
    ]);

    $user->assignRole('employee');

    Employee::query()->create([
        'user_id' => $user->id,
        'employee_id' => 'EMP001',
        'full_name' => 'Employee User',
        'join_date' => now()->toDateString(),
        'employment_status' => 'resigned',
    ]);

    $response = loginWithDevice($this);

    $response
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors('email');
});

test('invalid login is rejected', function () {
    User::factory()->create([
        'email' => 'employee@example.com',
        'password' => 'secret-password',
    ]);

    $response = loginWithDevice($this, ['password' => 'wrong-password']);

    $response
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors('email');
});

test('inactive users cannot login', function () {
    User::factory()->create([
        'email' => 'inactive@example.com',
        'password' => 'secret-password',
        'status' => 'inactive',
    ]);

    $response = loginWithDevice($this, ['email' => 'inactive@example.com']);

    $response
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors('email');
});

test('the authenticated user can be fetched from the me endpoint', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create([
        'name' => 'Admin User',
        'email' => 'admin@example.com',
    ]);

    $user->assignRole('employee');

    Employee::query()->create([
        'user_id' => $user->id,
        'employee_id' => 'EMP001',
        'full_name' => 'Admin User',
        'join_date' => now()->toDateString(),
    ]);

    $headers = deviceHeaders();
    $token = createTokenWithFingerprint($user, $headers);

    $response = $this->withToken($token)
        ->withHeaders($headers)
        ->getJson('/api/me');

    $response
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Authenticated user fetched successfully.')
        ->assertJsonPath('data.email', 'admin@example.com')
        ->assertJsonPath('data.roles.0', 'employee')
        ->assertJsonPath('data.employee.employee_id', 'EMP001')
        ->assertJsonPath('data.employee.full_name', 'Admin User');

    expect($response->json('data.permissions'))
        ->toContain('attendance.clock_in', 'leaves.create', 'payslips.view_own')
        ->not->toContain('roles_permissions.manage');
});

test('the authenticated user can logout and revoke the current token', function () {
    $user = User::factory()->create();
    $headers = deviceHeaders();
    $token = createTokenWithFingerprint($user, $headers);

    $response = $this->withToken($token)
        ->withHeaders($headers)
        ->postJson('/api/logout');

    $response
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Logout successful.');

    expect($user->fresh()->tokens()->count())->toBe(0);
});

test('request from a different device is rejected', function () {
    $user = User::factory()->create();
    $token = createTokenWithFingerprint($user, ['User-Agent' => 'OriginalBrowser/1.0']);

    $response = $this->withToken($token)
        ->withHeaders(deviceHeaders(['User-Agent' => 'DifferentBrowser/2.0']))
        ->getJson('/api/me');

    $response->assertStatus(401)
        ->assertJsonPath('message', 'Device mismatch. Please log in again.');

    expect($user->fresh()->tokens()->count())->toBe(0);
});

test('login on same device replaces the old token', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create([
        'email' => 'employee@example.com',
        'password' => 'secret-password',
    ]);

    $user->assignRole('admin');

    $headers = deviceHeaders();

    loginWithDevice($this, [], $headers)->assertSuccessful();
    expect($user->tokens()->count())->toBe(1);

    loginWithDevice($this, [], $headers)->assertSuccessful();
    expect($user->tokens()->count())->toBe(1);
});

test('login requests are rate limited after five attempts per minute', function () {
    User::factory()->create([
        'email' => 'employee@example.com',
        'password' => 'secret-password',
    ]);

    foreach (range(1, 5) as $attempt) {
        $response = loginWithDevice($this, ['password' => 'wrong-password']);
        $response->assertUnprocessable();
    }

    $response = loginWithDevice($this, ['password' => 'wrong-password']);

    $response
        ->assertStatus(429)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Too many login attempts. Please try again later.');
});
