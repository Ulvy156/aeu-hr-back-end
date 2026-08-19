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
        ->and(Permission::findByName('attendance.view_correction', 'web'))->not->toBeNull()
        ->and(Permission::findByName('users.assign_permissions', 'web'))->not->toBeNull()
        ->and(Permission::findByName('payrolls.approve', 'web'))->not->toBeNull()
        ->and(Role::findByName('employee', 'web')->hasPermissionTo('attendance.clock_in'))->toBeTrue()
        ->and(Role::findByName('hr', 'web')->hasPermissionTo('attendance.view_correction'))->toBeTrue()
        ->and(Role::findByName('employee', 'web')->hasPermissionTo('attendance.view_correction'))->toBeFalse()
        ->and(Role::findByName('admin', 'web')->hasPermissionTo('users.assign_permissions'))->toBeTrue()
        ->and(Role::findByName('ceo', 'web')->hasPermissionTo('audit_logs.view'))->toBeTrue()
        ->and(Role::findByName('hr', 'web')->hasPermissionTo('departments.create'))->toBeTrue();
});

test('leave permissions are assigned with least privilege by role', function () {
    $this->seed(RoleSeeder::class);

    $admin = Role::findByName('admin', 'web');
    $hr = Role::findByName('hr', 'web');
    $ceo = Role::findByName('ceo', 'web');
    $employee = Role::findByName('employee', 'web');

    expect($admin->hasPermissionTo('leaves.view_any'))->toBeTrue()
        ->and($admin->hasPermissionTo('leaves.approve_hr'))->toBeTrue()
        ->and($admin->hasPermissionTo('leaves.approve_ceo'))->toBeTrue()
        ->and($admin->hasPermissionTo('leaves.cancel'))->toBeTrue()
        ->and($hr->hasPermissionTo('leaves.view_any'))->toBeTrue()
        ->and($hr->hasPermissionTo('leaves.approve_hr'))->toBeTrue()
        ->and($hr->hasPermissionTo('leaves.reject_hr'))->toBeTrue()
        ->and($hr->hasPermissionTo('leaves.create'))->toBeFalse()
        ->and($hr->hasPermissionTo('leaves.cancel'))->toBeFalse()
        ->and($hr->hasPermissionTo('leaves.approve_ceo'))->toBeFalse()
        ->and($ceo->hasPermissionTo('leaves.view_any'))->toBeTrue()
        ->and($ceo->hasPermissionTo('leaves.approve_ceo'))->toBeTrue()
        ->and($ceo->hasPermissionTo('leaves.reject_ceo'))->toBeTrue()
        ->and($ceo->hasPermissionTo('leaves.create'))->toBeFalse()
        ->and($ceo->hasPermissionTo('leaves.cancel'))->toBeFalse()
        ->and($ceo->hasPermissionTo('leaves.approve_hr'))->toBeFalse()
        ->and($employee->hasPermissionTo('leaves.view_own'))->toBeTrue()
        ->and($employee->hasPermissionTo('leaves.create'))->toBeTrue()
        ->and($employee->hasPermissionTo('leaves.cancel'))->toBeTrue()
        ->and($employee->hasPermissionTo('leaves.view_any'))->toBeFalse()
        ->and($employee->hasPermissionTo('leaves.approve_hr'))->toBeFalse()
        ->and($employee->hasPermissionTo('leaves.approve_ceo'))->toBeFalse();
});

test('admin role receives every seeded permission in the users group', function () {
    $this->seed(RoleSeeder::class);

    $usersPermissions = collect(array_keys(config('hr_permissions.groups.users')))
        ->filter()
        ->values();

    $adminRole = Role::findByName('admin', 'web');

    expect($usersPermissions)->not->toBeEmpty();

    foreach ($usersPermissions as $permissionName) {
        expect(Permission::findByName($permissionName, 'web'))->not->toBeNull()
            ->and($adminRole->hasPermissionTo($permissionName))->toBeTrue();
    }
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
