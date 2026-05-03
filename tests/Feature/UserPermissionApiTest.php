<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('unauthenticated users cannot access user permission endpoints', function () {
    $user = User::factory()->create();

    $this->getJson("/api/users/{$user->id}/permissions")
        ->assertUnauthorized();

    $this->putJson("/api/users/{$user->id}/permissions", [
        'permissions' => ['attendance.view_correction'],
    ])->assertUnauthorized();

    $this->postJson("/api/users/{$user->id}/permissions", [
        'permission' => 'attendance.view_correction',
    ])->assertUnauthorized();

    $this->deleteJson("/api/users/{$user->id}/permissions", [
        'permission' => 'attendance.view_correction',
    ])->assertUnauthorized();
});

test('non admin users cannot assign direct permissions', function () {
    $targetUser = User::factory()->create();
    $targetUser->assignRole('employee');

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->putJson("/api/users/{$targetUser->id}/permissions", [
            'permissions' => ['attendance.view_correction'],
        ])
        ->assertForbidden();
});

test('admin can view user direct role and effective permissions', function () {
    $targetUser = User::factory()->create([
        'email' => 'employee.permissions@example.com',
    ]);
    $targetUser->assignRole('employee');
    $targetUser->givePermissionTo('attendance.view_correction');

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $response = $this->withToken($token)
        ->getJson("/api/users/{$targetUser->id}/permissions");

    $response
        ->assertSuccessful()
        ->assertJsonPath('message', 'User permissions fetched successfully.')
        ->assertJsonPath('data.user_id', $targetUser->id);

    expect($response->json('data.direct_permissions'))
        ->toContain('attendance.view_correction');
    expect($response->json('data.role_permissions'))
        ->toContain('attendance.clock_in', 'attendance.clock_out', 'attendance.view_own');
    expect($response->json('data.all_permissions'))
        ->toContain('attendance.view_correction', 'attendance.clock_in')
        ->not->toContain('users.assign_permissions');
});

test('admin can sync direct permissions without removing role permissions', function () {
    $targetUser = User::factory()->create();
    $targetUser->assignRole('employee');
    $targetUser->givePermissionTo('attendance.view_correction');

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $response = $this->withToken($token)
        ->putJson("/api/users/{$targetUser->id}/permissions", [
            'permissions' => ['attendance.view_any'],
        ]);

    $response
        ->assertSuccessful()
        ->assertJsonPath('message', 'User direct permissions updated successfully.');

    expect($response->json('data.direct_permissions'))->toBe(['attendance.view_any']);
    expect($response->json('data.role_permissions'))->toContain('attendance.clock_in');
    expect($response->json('data.all_permissions'))->toContain('attendance.view_any', 'attendance.clock_in');
    expect($targetUser->fresh()->getDirectPermissions()->pluck('name')->all())->toBe(['attendance.view_any']);
});

test('admin can add one direct permission without duplicating it', function () {
    $targetUser = User::factory()->create();
    $targetUser->assignRole('employee');

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $this->withToken($token)
        ->postJson("/api/users/{$targetUser->id}/permissions", [
            'permission' => 'attendance.view_correction',
        ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'User direct permission added successfully.');

    $secondResponse = $this->withToken($token)
        ->postJson("/api/users/{$targetUser->id}/permissions", [
            'permission' => 'attendance.view_correction',
        ]);

    $secondResponse->assertSuccessful();

    expect($secondResponse->json('data.direct_permissions'))->toBe(['attendance.view_correction']);
});

test('admin can remove one direct permission', function () {
    $targetUser = User::factory()->create();
    $targetUser->assignRole('employee');
    $targetUser->givePermissionTo('attendance.view_correction');

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $response = $this->withToken($token)
        ->deleteJson("/api/users/{$targetUser->id}/permissions", [
            'permission' => 'attendance.view_correction',
        ]);

    $response
        ->assertSuccessful()
        ->assertJsonPath('message', 'User direct permission removed successfully.');

    expect($response->json('data.direct_permissions'))->toBe([])
        ->and($targetUser->fresh()->hasDirectPermission('attendance.view_correction'))->toBeFalse();
});

test('removing a direct permission does not remove the same permission granted by role', function () {
    $targetUser = User::factory()->create();
    $targetUser->assignRole('employee');
    $targetUser->givePermissionTo('attendance.clock_in');

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $response = $this->withToken($token)
        ->deleteJson("/api/users/{$targetUser->id}/permissions", [
            'permission' => 'attendance.clock_in',
        ]);

    $response->assertSuccessful();

    expect($response->json('data.direct_permissions'))->toBe([])
        ->and($response->json('data.role_permissions'))->toContain('attendance.clock_in')
        ->and($response->json('data.all_permissions'))->toContain('attendance.clock_in')
        ->and($targetUser->fresh()->hasDirectPermission('attendance.clock_in'))->toBeFalse()
        ->and($targetUser->fresh()->hasPermissionTo('attendance.clock_in'))->toBeTrue();
});

test('unknown permission names are rejected by validation', function () {
    $targetUser = User::factory()->create();
    $targetUser->assignRole('employee');

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $this->withToken($token)
        ->putJson("/api/users/{$targetUser->id}/permissions", [
            'permissions' => ['not-a-real-permission'],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors('permissions.0');
});

test('admin cannot assign direct permissions to self', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $this->withToken($token)
        ->postJson("/api/users/{$admin->id}/permissions", [
            'permission' => 'attendance.view_correction',
        ])
        ->assertForbidden();
});

test('direct permission responses expose only permission summary fields', function () {
    $targetUser = User::factory()->create();
    $targetUser->assignRole('employee');
    $targetUser->givePermissionTo(Permission::findByName('attendance.view_correction', 'web'));

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $this->withToken($token)
        ->getJson("/api/users/{$targetUser->id}/permissions")
        ->assertSuccessful()
        ->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'user_id',
                'direct_permissions',
                'role_permissions',
                'all_permissions',
            ],
        ])
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.tokens');
});
