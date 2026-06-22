<?php

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function loginAndGetTokens(mixed $testCase, array $payload = []): array
{
    $defaultPayload = [
        'email' => 'employee@example.com',
        'password' => 'secret-password',
        'device_name' => 'web-client',
    ];

    $response = $testCase->postJson('/api/login', array_merge($defaultPayload, $payload));

    return [
        'response' => $response,
        'access_token' => $response->json('data.access_token'),
    ];
}

test('a user can login and receive an access token', function () {
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

    $result = loginAndGetTokens($this);

    $result['response']
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Login successful.')
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.email', 'employee@example.com')
        ->assertJsonPath('data.user.roles.0', 'employee')
        ->assertJsonPath('data.user.employee.employee_id', 'EMP001');

    expect($result['response']->json('data.access_token'))->toBeString()->not->toBeEmpty();
    expect($result['response']->json('data.expires_in'))->toBeInt()->toBeGreaterThan(0);
    expect($user->tokens()->count())->toBe(1);
});

test('admin can login without an employee profile', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create([
        'email' => 'admin@example.com',
        'password' => 'secret-password',
    ]);

    $user->assignRole('admin');

    $result = loginAndGetTokens($this, ['email' => 'admin@example.com']);

    $result['response']
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

    $result = loginAndGetTokens($this);

    $result['response']
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

    $result = loginAndGetTokens($this, ['email' => 'probation@example.com']);

    $result['response']
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

    $result = loginAndGetTokens($this);

    $result['response']
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

    $result = loginAndGetTokens($this, ['password' => 'wrong-password']);

    $result['response']
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

    $result = loginAndGetTokens($this, ['email' => 'inactive@example.com']);

    $result['response']
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

    $token = $user->createToken('test-device')->plainTextToken;

    $response = $this->withToken($token)
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
    $token = $user->createToken('test-device')->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/logout');

    $response
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Logout successful.');

    expect($user->fresh()->tokens()->count())->toBe(0);
});

test('login on same device replaces old token', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create([
        'email' => 'employee@example.com',
        'password' => 'secret-password',
    ]);

    $user->assignRole('admin');

    loginAndGetTokens($this)['response']->assertSuccessful();
    expect($user->tokens()->count())->toBe(1);

    loginAndGetTokens($this)['response']->assertSuccessful();
    expect($user->tokens()->count())->toBe(1);
});

test('login requests are rate limited after five attempts per minute', function () {
    User::factory()->create([
        'email' => 'employee@example.com',
        'password' => 'secret-password',
    ]);

    foreach (range(1, 5) as $attempt) {
        $result = loginAndGetTokens($this, ['password' => 'wrong-password']);
        $result['response']->assertUnprocessable();
    }

    $result = loginAndGetTokens($this, ['password' => 'wrong-password']);

    $result['response']
        ->assertStatus(429)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Too many login attempts. Please try again later.');
});
