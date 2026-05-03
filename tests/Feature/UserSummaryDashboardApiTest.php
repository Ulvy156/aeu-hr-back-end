<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('authorized admin can access the user summary dashboard with correct counts', function () {
    $admin = User::factory()->create([
        'status' => 'active',
    ]);
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    User::factory()->create([
        'status' => 'active',
    ])->assignRole('hr');

    User::factory()->create([
        'status' => 'inactive',
    ])->assignRole('hr');

    User::factory()->create([
        'status' => 'active',
    ])->assignRole('ceo');

    User::factory()->create([
        'status' => 'active',
    ])->assignRole('employee');

    User::factory()->create([
        'status' => 'active',
    ])->assignRole('employee');

    User::factory()->create([
        'status' => 'inactive',
    ])->assignRole('employee');

    $this->withToken($token)
        ->getJson('/api/dashboard/users-summary')
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'User summary fetched successfully')
        ->assertJsonPath('data.total_users', 7)
        ->assertJsonPath('data.active_users', 5)
        ->assertJsonPath('data.inactive_users', 2)
        ->assertJsonPath('data.users_by_role.admin', 1)
        ->assertJsonPath('data.users_by_role.hr', 2)
        ->assertJsonPath('data.users_by_role.ceo', 1)
        ->assertJsonPath('data.users_by_role.employee', 3);
});

test('unauthorized user cannot access the user summary dashboard', function () {
    $hr = User::factory()->create([
        'status' => 'active',
    ]);
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/dashboard/users-summary')
        ->assertForbidden();
});
