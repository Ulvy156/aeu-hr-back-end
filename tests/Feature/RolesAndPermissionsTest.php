<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Route::middleware(['auth:sanctum', 'permission:departments.view_any'])
        ->get('/api/test-permission-guard', fn () => response()->json([
            'success' => true,
        ]));

    Route::middleware(['auth:sanctum', 'role:admin'])
        ->get('/api/test-role-guard', fn () => response()->json([
            'success' => true,
        ]));

    Route::middleware(['auth:sanctum', 'can:viewAny,'.User::class])
        ->get('/api/test-user-policy', fn () => response()->json([
            'success' => true,
        ]));
});

test('roles and permissions are seeded with grouped assignments', function () {
    $this->seed(RoleSeeder::class);

    expect(Role::findByName('admin', 'web'))->not->toBeNull()
        ->and(Role::findByName('hr', 'web'))->not->toBeNull()
        ->and(Role::findByName('ceo', 'web'))->not->toBeNull()
        ->and(Role::findByName('employee', 'web'))->not->toBeNull()
        ->and(Permission::findByName('departments.view_any', 'web'))->not->toBeNull()
        ->and(Permission::findByName('payrolls.approve', 'web'))->not->toBeNull()
        ->and(Role::findByName('employee', 'web')->hasPermissionTo('attendance.clock_in'))->toBeTrue()
        ->and(Role::findByName('ceo', 'web')->hasPermissionTo('audit_logs.view'))->toBeTrue()
        ->and(Role::findByName('hr', 'web')->hasPermissionTo('departments.create'))->toBeTrue();
});

test('role and permission helpers work on the user model', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('hr');

    expect($user->hasRole('hr'))->toBeTrue()
        ->and($user->can('employees.view_any'))->toBeTrue()
        ->and($user->can('audit_logs.view'))->toBeFalse();
});

test('permission middleware rejects unauthenticated users', function () {
    $this->seed(RoleSeeder::class);

    $this->getJson('/api/test-permission-guard')
        ->assertUnauthorized();
});

test('permission middleware rejects authenticated users without permission', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('employee');
    $token = $user->createToken('test-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/test-permission-guard')
        ->assertForbidden();
});

test('permission middleware allows users with the required permission', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('hr');
    $token = $user->createToken('test-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/test-permission-guard')
        ->assertSuccessful()
        ->assertJsonPath('success', true);
});

test('role middleware rejects users without the required role', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('hr');
    $token = $user->createToken('test-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/test-role-guard')
        ->assertForbidden();
});

test('role middleware allows users with the required role', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('admin');
    $token = $user->createToken('test-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/test-role-guard')
        ->assertSuccessful()
        ->assertJsonPath('success', true);
});

test('policy checks work through the gate for protected routes', function () {
    $this->seed(RoleSeeder::class);

    $employee = User::factory()->create();
    $employee->assignRole('employee');

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $adminToken = $admin->createToken('admin-device')->plainTextToken;

    expect($employee->can('viewAny', User::class))->toBeFalse();

    $this->withToken($adminToken)
        ->getJson('/api/test-user-policy')
        ->assertSuccessful()
        ->assertJsonPath('success', true);

    expect($admin->can('viewAny', User::class))->toBeTrue();
});
