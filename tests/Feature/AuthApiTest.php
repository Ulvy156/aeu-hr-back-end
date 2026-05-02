<?php

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a user can login and receive a sanctum token', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create([
        'email' => 'employee@example.com',
        'password' => 'secret-password',
    ]);

    $user->assignRole('employee');

    $response = $this->postJson('/api/login', [
        'email' => 'employee@example.com',
        'password' => 'secret-password',
        'device_name' => 'web-client',
    ]);

    $response
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Login successful.')
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.email', 'employee@example.com')
        ->assertJsonPath('data.user.roles.0', 'employee')
        ->assertJsonPath('data.user.permissions.0', 'attendance.clock_in')
        ->assertJsonPath('data.user.employee', null);

    expect($user->tokens()->count())->toBe(1);
});

test('invalid login is rejected', function () {
    User::factory()->create([
        'email' => 'employee@example.com',
        'password' => 'secret-password',
    ]);

    $response = $this->postJson('/api/login', [
        'email' => 'employee@example.com',
        'password' => 'wrong-password',
    ]);

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

    $response = $this->postJson('/api/login', [
        'email' => 'inactive@example.com',
        'password' => 'secret-password',
    ]);

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

    $token = $user->createToken('test-device')->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/me');

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

    $response = $this->withToken($token)->postJson('/api/logout');

    $response
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Logout successful.');

    expect($user->fresh()->tokens()->count())->toBe(0);
});

test('login requests are rate limited after five attempts per minute', function () {
    User::factory()->create([
        'email' => 'employee@example.com',
        'password' => 'secret-password',
    ]);

    foreach (range(1, 5) as $attempt) {
        $response = $this->postJson('/api/login', [
            'email' => 'employee@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable();
    }

    $response = $this->postJson('/api/login', [
        'email' => 'employee@example.com',
        'password' => 'wrong-password',
    ]);

    $response
        ->assertStatus(429)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Too many login attempts. Please try again later.');
});
