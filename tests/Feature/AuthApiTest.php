<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('a user can login and receive a sanctum token', function () {
    Role::findOrCreate('employee', 'web');

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
        ->assertJsonPath('data.user.roles.0', 'employee');

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
    Role::findOrCreate('employee', 'web');

    $user = User::factory()->create([
        'email' => 'employee@example.com',
    ]);

    $user->assignRole('employee');

    $token = $user->createToken('test-device')->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/me');

    $response
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.email', 'employee@example.com')
        ->assertJsonPath('data.roles.0', 'employee');
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
