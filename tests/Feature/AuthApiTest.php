<?php

use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;

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

    $position = Position::query()->create([
        'name' => 'Sales Executive',
        'status' => 'active',
    ]);

    Employee::query()->create([
        'user_id' => $user->id,
        'employee_id' => 'EMP001',
        'full_name' => 'Employee User',
        'position_id' => $position->id,
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
        ->assertJsonPath('data.user.employee.employee_id', 'EMP001')
        ->assertJsonPath('data.user.employee.position.id', $position->id)
        ->assertJsonPath('data.user.employee.position.name', 'Sales Executive');

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

    $position = Position::query()->create([
        'name' => 'Account & Stock',
        'status' => 'active',
    ]);

    Employee::query()->create([
        'user_id' => $user->id,
        'employee_id' => 'EMP001',
        'full_name' => 'Admin User',
        'position_id' => $position->id,
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
        ->assertJsonPath('data.employee.full_name', 'Admin User')
        ->assertJsonPath('data.employee.position.id', $position->id)
        ->assertJsonPath('data.employee.position.name', 'Account & Stock');

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

test('five devices can stay logged in concurrently for one account', function () {
    // Many intentional logins in one test; skip the 5/min login throttle.
    $this->withoutMiddleware(ThrottleRequests::class);
    $this->seed(RoleSeeder::class);

    $email = 'multi-device@example.com';

    $user = User::factory()->create([
        'email' => $email,
        'password' => 'secret-password',
    ]);

    $user->assignRole('admin');

    $tokens = [];

    foreach (range(1, 5) as $device) {
        $result = loginAndGetTokens($this, [
            'email' => $email,
            'device_name' => "web-device-{$device}",
        ]);

        $result['response']->assertSuccessful();
        $tokens[$device] = $result['access_token'];
    }

    expect($user->fresh()->tokens()->count())->toBe(5);
    expect($user->fresh()->tokens()->pluck('name')->sort()->values()->all())->toBe([
        'web-device-1',
        'web-device-2',
        'web-device-3',
        'web-device-4',
        'web-device-5',
    ]);

    foreach ($tokens as $token) {
        $this->withToken($token)
            ->getJson('/api/me')
            ->assertSuccessful()
            ->assertJsonPath('data.email', $email);
    }

    $replaced = loginAndGetTokens($this, [
        'email' => $email,
        'device_name' => 'web-device-1',
    ]);
    $replaced['response']->assertSuccessful();

    expect($user->fresh()->tokens()->count())->toBe(5);

    // Ensure each request re-resolves auth from the Authorization header.
    $usingToken = function (string $token) {
        Auth::forgetGuards();

        return $this->withToken($token);
    };

    $usingToken($tokens[1])->getJson('/api/me')->assertUnauthorized();
    $usingToken($replaced['access_token'])
        ->getJson('/api/me')
        ->assertSuccessful()
        ->assertJsonPath('data.email', $email);

    foreach (range(2, 5) as $device) {
        $usingToken($tokens[$device])
            ->getJson('/api/me')
            ->assertSuccessful()
            ->assertJsonPath('data.email', $email);
    }

    $usingToken($tokens[2])->postJson('/api/logout')->assertSuccessful();

    expect($user->fresh()->tokens()->count())->toBe(4);
    $usingToken($tokens[2])->getJson('/api/me')->assertUnauthorized();

    foreach ([3, 4, 5] as $device) {
        $usingToken($tokens[$device])
            ->getJson('/api/me')
            ->assertSuccessful();
    }

    $usingToken($replaced['access_token'])
        ->getJson('/api/me')
        ->assertSuccessful();
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
