<?php

use App\Models\Employee;
use App\Models\RefreshToken;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Cookie\Middleware\EncryptCookies;
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
        'refresh_cookie' => collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === 'refresh_token'),
    ];
}

function getRefreshTokenPlain(User $user): ?string
{
    $refreshToken = $user->refreshTokens()->whereNull('revoked_at')->latest()->first();

    return $refreshToken ? $refreshToken->getOriginal('token') : null;
}

function postWithCookie(mixed $testCase, string $uri, string $cookieName, string $cookieValue): mixed
{
    return $testCase->call(
        method: 'POST',
        uri: $uri,
        cookies: [$cookieName => $cookieValue],
        server: ['HTTP_ACCEPT' => 'application/json'],
    );
}

test('a user can login and receive an access token with refresh token cookie', function () {
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
    expect($user->refreshTokens()->count())->toBe(1);

    $cookie = $result['refresh_cookie'];
    expect($cookie)->not->toBeNull();
    expect($cookie->isHttpOnly())->toBeTrue();
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

test('refresh token can issue a new access token', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create([
        'email' => 'employee@example.com',
        'password' => 'secret-password',
    ]);

    $user->assignRole('admin');

    $result = loginAndGetTokens($this);
    $result['response']->assertSuccessful();

    $refreshToken = $user->refreshTokens()->whereNull('revoked_at')->first();
    expect($refreshToken)->not->toBeNull();

    $plainToken = Illuminate\Support\Str::random(64);
    $user->refreshTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
    $user->refreshTokens()->create([
        'token' => hash('sha256', $plainToken),
        'device_name' => 'web-client',
        'expires_at' => now()->addDays(7),
    ]);

    $response = $this->withoutMiddleware(EncryptCookies::class)
        ->call('POST', '/api/refresh', [], ['refresh_token' => $plainToken], [], ['HTTP_ACCEPT' => 'application/json']);

    $response
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Token refreshed successfully.')
        ->assertJsonPath('data.token_type', 'Bearer');

    expect($response->json('data.access_token'))->toBeString()->not->toBeEmpty();
    expect($response->json('data.expires_in'))->toBeInt()->toBeGreaterThan(0);

    expect($user->tokens()->count())->toBe(1);
    expect($user->refreshTokens()->whereNull('revoked_at')->count())->toBe(1);
    expect($user->refreshTokens()->whereNotNull('revoked_at')->count())->toBeGreaterThanOrEqual(1);
});

test('refresh with missing cookie returns 401', function () {
    $response = $this->postJson('/api/refresh');

    $response
        ->assertStatus(401)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Refresh token not found.');
});

test('refresh with expired token returns 403', function () {
    $user = User::factory()->create();

    $plainToken = Illuminate\Support\Str::random(64);
    $user->refreshTokens()->create([
        'token' => hash('sha256', $plainToken),
        'device_name' => 'api-token',
        'expires_at' => now()->subDay(),
    ]);

    $response = $this->withoutMiddleware(EncryptCookies::class)
        ->call('POST', '/api/refresh', [], ['refresh_token' => $plainToken], [], ['HTTP_ACCEPT' => 'application/json']);

    $response
        ->assertStatus(403)
        ->assertJsonPath('message', 'Invalid or expired refresh token.');
});

test('refresh with revoked token returns 403', function () {
    $user = User::factory()->create();

    $plainToken = Illuminate\Support\Str::random(64);
    $user->refreshTokens()->create([
        'token' => hash('sha256', $plainToken),
        'device_name' => 'api-token',
        'expires_at' => now()->addDays(7),
        'revoked_at' => now(),
    ]);

    $response = $this->withoutMiddleware(EncryptCookies::class)
        ->call('POST', '/api/refresh', [], ['refresh_token' => $plainToken], [], ['HTTP_ACCEPT' => 'application/json']);

    $response
        ->assertStatus(403)
        ->assertJsonPath('message', 'Invalid or expired refresh token.');
});

test('refresh for inactive user returns 403', function () {
    $user = User::factory()->create(['status' => 'active']);

    $plainToken = Illuminate\Support\Str::random(64);
    $user->refreshTokens()->create([
        'token' => hash('sha256', $plainToken),
        'device_name' => 'api-token',
        'expires_at' => now()->addDays(7),
    ]);

    $user->update(['status' => 'inactive']);

    $response = $this->withoutMiddleware(EncryptCookies::class)
        ->call('POST', '/api/refresh', [], ['refresh_token' => $plainToken], [], ['HTTP_ACCEPT' => 'application/json']);

    $response
        ->assertStatus(403)
        ->assertJsonPath('message', 'Account is inactive.');
});

test('login on same device replaces old tokens', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create([
        'email' => 'employee@example.com',
        'password' => 'secret-password',
    ]);

    $user->assignRole('admin');

    loginAndGetTokens($this)['response']->assertSuccessful();
    expect($user->tokens()->count())->toBe(1);
    expect($user->refreshTokens()->whereNull('revoked_at')->count())->toBe(1);

    loginAndGetTokens($this)['response']->assertSuccessful();
    expect($user->tokens()->count())->toBe(1);
    expect($user->refreshTokens()->whereNull('revoked_at')->count())->toBe(1);
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

test('logout revokes refresh token and clears cookie', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create([
        'email' => 'employee@example.com',
        'password' => 'secret-password',
    ]);

    $user->assignRole('admin');

    $result = loginAndGetTokens($this);
    $result['response']->assertSuccessful();

    $accessToken = $result['access_token'];

    $plainRefreshToken = Illuminate\Support\Str::random(64);
    $user->refreshTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
    $user->refreshTokens()->create([
        'token' => hash('sha256', $plainRefreshToken),
        'device_name' => 'web-client',
        'expires_at' => now()->addDays(7),
    ]);

    $response = $this->withoutMiddleware(EncryptCookies::class)
        ->withToken($accessToken)
        ->call('POST', '/api/logout', [], ['refresh_token' => $plainRefreshToken], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$accessToken,
        ]);

    $response->assertSuccessful();

    expect($user->fresh()->tokens()->count())->toBe(0);
    expect($user->refreshTokens()->whereNull('revoked_at')->count())->toBe(0);

    $clearCookie = collect($response->headers->getCookies())
        ->first(fn ($c) => $c->getName() === 'refresh_token');
    expect($clearCookie)->not->toBeNull();
});
