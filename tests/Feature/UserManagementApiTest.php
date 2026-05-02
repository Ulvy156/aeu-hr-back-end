<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('admin can list users with search filters and roles without exposing sensitive fields', function () {
    $department = Department::query()->create([
        'name' => 'Finance',
        'status' => 'active',
    ]);

    $position = Position::query()->create([
        'name' => 'Accountant',
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    $targetUser = User::factory()->create([
        'name' => 'Finance User',
        'email' => 'finance.user@example.com',
        'status' => 'active',
    ]);
    $targetUser->assignRole('employee');

    Employee::query()->create([
        'user_id' => $targetUser->id,
        'employee_id' => 'EMP300',
        'full_name' => 'Finance User',
        'email' => 'finance.user@example.com',
        'department_id' => $department->id,
        'position_id' => $position->id,
        'join_date' => now()->toDateString(),
        'base_salary' => 1200,
        'employment_status' => 'active',
    ]);

    User::factory()->create([
        'name' => 'Inactive User',
        'email' => 'inactive.user@example.com',
        'status' => 'inactive',
    ])->assignRole('employee');

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/users?search=Finance&status=active&per_page=10');

    $response
        ->assertSuccessful()
        ->assertJsonPath('message', 'Users fetched successfully.')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.email', 'finance.user@example.com')
        ->assertJsonPath('data.0.roles.0', 'employee')
        ->assertJsonPath('data.0.employee.department.name', 'Finance')
        ->assertJsonMissingPath('data.0.password')
        ->assertJsonMissingPath('data.0.remember_token');
});

test('admin can view user detail with permissions and linked employee profile', function () {
    $department = Department::query()->create([
        'name' => 'Operations',
        'status' => 'active',
    ]);

    $position = Position::query()->create([
        'name' => 'Officer',
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    $targetUser = User::factory()->create([
        'name' => 'HR User',
        'email' => 'hr.user@example.com',
    ]);
    $targetUser->assignRole('hr');

    Employee::query()->create([
        'user_id' => $targetUser->id,
        'employee_id' => 'EMP301',
        'full_name' => 'HR User',
        'email' => 'hr.user@example.com',
        'department_id' => $department->id,
        'position_id' => $position->id,
        'join_date' => now()->toDateString(),
        'base_salary' => 1000,
        'employment_status' => 'active',
    ]);

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $response = $this->withToken($token)
        ->getJson("/api/users/{$targetUser->id}");

    $response
        ->assertSuccessful()
        ->assertJsonPath('message', 'User fetched successfully.')
        ->assertJsonPath('data.email', 'hr.user@example.com')
        ->assertJsonPath('data.employee.employee_id', 'EMP301')
        ->assertJsonPath('data.employee.position.name', 'Officer')
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.remember_token');

    expect($response->json('data.permissions'))
        ->toContain('employees.view_any', 'departments.create')
        ->not->toContain('users.create');
});

test('admin can create user with roles and password is hashed', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $response = $this->withToken($token)->postJson('/api/users', [
        'name' => 'CEO User',
        'email' => 'ceo.user@example.com',
        'password' => 'secretpass123',
        'status' => 'active',
        'roles' => ['ceo'],
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('message', 'User created successfully.')
        ->assertJsonPath('data.email', 'ceo.user@example.com')
        ->assertJsonPath('data.roles.0', 'ceo')
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.remember_token');

    $createdUser = User::query()->where('email', 'ceo.user@example.com')->firstOrFail();

    expect($createdUser->hasRole('ceo'))->toBeTrue()
        ->and($createdUser->getRoleNames())->toHaveCount(1)
        ->and(Hash::check('secretpass123', $createdUser->password))->toBeTrue()
        ->and(Activity::query()->where('log_name', 'users')->where('description', 'create')->exists())->toBeTrue();
});

test('user creation rejects multiple roles', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $this->withToken($token)->postJson('/api/users', [
        'name' => 'Multi Role User',
        'email' => 'multi.role@example.com',
        'password' => 'secretpass123',
        'status' => 'active',
        'roles' => ['hr', 'employee'],
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors('roles');
});

test('admin can update user basic info without changing role', function () {
    $targetUser = User::factory()->create([
        'name' => 'Original User',
        'email' => 'original.user@example.com',
        'status' => 'active',
    ]);
    $targetUser->assignRole('employee');
    $targetUser->createToken('target-device');

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $this->withToken($token)
        ->putJson("/api/users/{$targetUser->id}", [
            'name' => 'Updated User',
            'email' => 'updated.user@example.com',
            'status' => 'active',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'Updated User')
        ->assertJsonPath('data.email', 'updated.user@example.com')
        ->assertJsonPath('data.roles.0', 'employee')
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.remember_token');

    expect($targetUser->fresh()->hasRole('employee'))->toBeTrue()
        ->and($targetUser->fresh()->getRoleNames())->toHaveCount(1);
});

test('admin can update user basic info and role together', function () {
    $targetUser = User::factory()->create([
        'name' => 'Original User',
        'email' => 'original.user@example.com',
        'status' => 'active',
    ]);
    $targetUser->assignRole('employee');

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $response = $this->withToken($token)
        ->putJson("/api/users/{$targetUser->id}", [
            'name' => 'HR User',
            'email' => 'hr.user.updated@example.com',
            'status' => 'active',
            'roles' => ['hr'],
        ]);

    $response
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'HR User')
        ->assertJsonPath('data.email', 'hr.user.updated@example.com')
        ->assertJsonPath('data.roles.0', 'hr')
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.remember_token');

    expect($targetUser->fresh()->hasRole('hr'))->toBeTrue()
        ->and($targetUser->fresh()->hasRole('employee'))->toBeFalse()
        ->and($targetUser->fresh()->getRoleNames())->toHaveCount(1);
});

test('user update rejects multiple roles', function () {
    $targetUser = User::factory()->create([
        'email' => 'update.roles@example.com',
        'status' => 'active',
    ]);
    $targetUser->assignRole('employee');

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $this->withToken($token)
        ->putJson("/api/users/{$targetUser->id}", [
            'name' => 'Updated User',
            'email' => 'update.roles@example.com',
            'status' => 'active',
            'roles' => ['hr', 'employee'],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors('roles');
});

test('user update rejects invalid roles', function () {
    $targetUser = User::factory()->create([
        'email' => 'invalid.role@example.com',
        'status' => 'active',
    ]);
    $targetUser->assignRole('employee');

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $this->withToken($token)
        ->putJson("/api/users/{$targetUser->id}", [
            'name' => 'Updated User',
            'email' => 'invalid.role@example.com',
            'status' => 'active',
            'roles' => ['not-a-real-role'],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors('roles.0');
});

test('admin can deactivate target accounts without exposing sensitive fields', function () {
    $targetUser = User::factory()->create([
        'name' => 'Original User',
        'email' => 'original.user@example.com',
        'status' => 'active',
    ]);
    $targetUser->assignRole('employee');
    $targetUser->createToken('target-device');

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $deleteResponse = $this->withToken($token)
        ->deleteJson("/api/users/{$targetUser->id}");

    $deleteResponse
        ->assertSuccessful()
        ->assertJsonPath('message', 'User deactivated successfully.')
        ->assertJsonPath('data.status', 'inactive');

    expect($targetUser->fresh()->status)->toBe('inactive')
        ->and($targetUser->fresh()->tokens()->count())->toBe(0);
});

test('admin cannot deactivate self through update or delete', function () {
    $admin = User::factory()->create([
        'status' => 'active',
    ]);
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $this->withToken($token)
        ->putJson("/api/users/{$admin->id}", [
            'name' => $admin->name,
            'email' => $admin->email,
            'status' => 'inactive',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    $this->withToken($token)
        ->deleteJson("/api/users/{$admin->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('user');
});

test('admin can list roles and permissions and sync user roles', function () {
    $targetUser = User::factory()->create([
        'email' => 'employee.user@example.com',
    ]);
    $targetUser->assignRole('employee');

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/roles')
        ->assertSuccessful()
        ->assertJsonPath('message', 'Roles fetched successfully.')
        ->assertJsonFragment([
            'name' => 'admin',
        ]);

    $this->withToken($token)
        ->getJson('/api/permissions')
        ->assertSuccessful()
        ->assertJsonPath('message', 'Permissions fetched successfully.')
        ->assertJsonFragment([
            'name' => 'users.assign_roles',
            'module' => 'users',
        ]);

    $response = $this->withToken($token)
        ->putJson("/api/users/{$targetUser->id}/roles", [
            'roles' => ['hr'],
        ]);

    $response
        ->assertSuccessful()
        ->assertJsonPath('message', 'User roles updated successfully.')
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.remember_token');

    expect($targetUser->fresh()->hasRole('hr'))->toBeTrue()
        ->and($targetUser->fresh()->hasRole('employee'))->toBeFalse()
        ->and($targetUser->fresh()->getRoleNames())->toHaveCount(1);
});

test('role sync rejects multiple roles', function () {
    $targetUser = User::factory()->create([
        'email' => 'sync.roles@example.com',
    ]);
    $targetUser->assignRole('employee');

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $this->withToken($token)
        ->putJson("/api/users/{$targetUser->id}/roles", [
            'roles' => ['hr', 'employee'],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors('roles');
});

test('non admin users cannot access user management endpoints', function () {
    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $targetUser = User::factory()->create();

    $this->withToken($token)
        ->getJson('/api/users')
        ->assertForbidden();

    $this->withToken($token)
        ->getJson('/api/roles')
        ->assertForbidden();

    $this->withToken($token)
        ->putJson("/api/users/{$targetUser->id}/roles", [
            'roles' => ['employee'],
        ])
        ->assertForbidden();
});
