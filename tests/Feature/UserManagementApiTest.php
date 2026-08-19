<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

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
        'department_id' => $department->id,
        'position_id' => $position->id,
        'join_date' => now()->toDateString(),
        'base_salary' => 1200,
        'employment_status' => 'full-time',
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

test('admin can filter users without linked employee profiles', function () {
    $linkedUser = User::factory()->create([
        'name' => 'Linked User',
        'email' => 'linked.filter@example.com',
        'status' => 'active',
    ]);
    $linkedUser->assignRole('employee');

    Employee::query()->create([
        'user_id' => $linkedUser->id,
        'employee_id' => 'EMP302',
        'full_name' => 'Linked User',
        'join_date' => now()->toDateString(),
        'base_salary' => 1000,
        'employment_status' => 'full-time',
    ]);

    $availableUser = User::factory()->create([
        'name' => 'Available User',
        'email' => 'available.filter@example.com',
        'status' => 'active',
    ]);
    $availableUser->assignRole('employee');

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/users?without_employee=1')
        ->assertSuccessful()
        ->assertJsonFragment([
            'id' => $availableUser->id,
            'email' => 'available.filter@example.com',
        ])
        ->assertJsonMissing([
            'id' => $linkedUser->id,
            'email' => 'linked.filter@example.com',
        ]);
});

test('admin can filter users without linked employee profiles while excluding admin users', function () {
    $linkedUser = User::factory()->create([
        'name' => 'Linked User',
        'email' => 'linked.exclude@example.com',
        'status' => 'active',
    ]);
    $linkedUser->assignRole('employee');

    Employee::query()->create([
        'user_id' => $linkedUser->id,
        'employee_id' => 'EMP303',
        'full_name' => 'Linked User',
        'join_date' => now()->toDateString(),
        'base_salary' => 1000,
        'employment_status' => 'full-time',
    ]);

    $adminCandidate = User::factory()->create([
        'name' => 'Admin Candidate',
        'email' => 'admin.candidate@example.com',
        'status' => 'active',
    ]);
    $adminCandidate->assignRole('admin');

    $availableUser = User::factory()->create([
        'name' => 'Available User',
        'email' => 'available.exclude@example.com',
        'status' => 'active',
    ]);
    $availableUser->assignRole('employee');

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/users?without_employee=1&exclude_admin=1')
        ->assertSuccessful()
        ->assertJsonFragment([
            'id' => $availableUser->id,
            'email' => 'available.exclude@example.com',
        ])
        ->assertJsonMissing([
            'id' => $linkedUser->id,
            'email' => 'linked.exclude@example.com',
        ])
        ->assertJsonMissing([
            'id' => $adminCandidate->id,
            'email' => 'admin.candidate@example.com',
        ]);
});

test('users with soft deleted employee profiles are excluded from without employee filter', function () {
    $softDeletedLinkedUser = User::factory()->create([
        'name' => 'Former Employee User',
        'email' => 'former.employee@example.com',
        'status' => 'inactive',
    ]);
    $softDeletedLinkedUser->assignRole('employee');

    $employee = Employee::query()->create([
        'user_id' => $softDeletedLinkedUser->id,
        'employee_id' => 'EMP304',
        'full_name' => 'Former Employee User',
        'join_date' => now()->toDateString(),
        'base_salary' => 1000,
        'employment_status' => 'terminated',
        'last_working_date' => now()->toDateString(),
    ]);
    $employee->delete();

    $availableUser = User::factory()->create([
        'name' => 'Available User',
        'email' => 'available.softdelete@example.com',
        'status' => 'active',
    ]);
    $availableUser->assignRole('employee');

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/users?without_employee=1')
        ->assertSuccessful()
        ->assertJsonFragment([
            'id' => $availableUser->id,
            'email' => 'available.softdelete@example.com',
        ])
        ->assertJsonMissing([
            'id' => $softDeletedLinkedUser->id,
            'email' => 'former.employee@example.com',
        ]);
});

test('soft deleted users are excluded from available employee user selector', function () {
    $softDeletedUser = User::factory()->create([
        'name' => 'Deleted User',
        'email' => 'deleted.selector@example.com',
        'status' => 'inactive',
    ]);
    $softDeletedUser->assignRole('employee');
    $softDeletedUser->delete();

    $availableUser = User::factory()->create([
        'name' => 'Available User',
        'email' => 'available.selector@example.com',
        'status' => 'active',
    ]);
    $availableUser->assignRole('employee');

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/users?without_employee=1&exclude_admin=1')
        ->assertSuccessful()
        ->assertJsonFragment([
            'id' => $availableUser->id,
            'email' => 'available.selector@example.com',
        ])
        ->assertJsonMissing([
            'id' => $softDeletedUser->id,
            'email' => 'deleted.selector@example.com',
        ]);
});

test('default user list behavior remains unchanged when no safe selector filters are provided', function () {
    $adminCandidate = User::factory()->create([
        'name' => 'Admin Candidate',
        'email' => 'admin.default@example.com',
        'status' => 'active',
    ]);
    $adminCandidate->assignRole('admin');

    $availableUser = User::factory()->create([
        'name' => 'Available User',
        'email' => 'available.default@example.com',
        'status' => 'active',
    ]);
    $availableUser->assignRole('employee');

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/users')
        ->assertSuccessful()
        ->assertJsonFragment([
            'id' => $adminCandidate->id,
            'email' => 'admin.default@example.com',
        ])
        ->assertJsonFragment([
            'id' => $availableUser->id,
            'email' => 'available.default@example.com',
        ]);
});

test('default user list excludes soft deleted users', function () {
    $visibleUser = User::factory()->create([
        'name' => 'Visible User',
        'email' => 'visible.user@example.com',
        'status' => 'active',
    ]);
    $visibleUser->assignRole('employee');

    $deletedUser = User::factory()->create([
        'name' => 'Deleted User',
        'email' => 'deleted.user@example.com',
        'status' => 'inactive',
    ]);
    $deletedUser->assignRole('employee');
    $deletedUser->delete();

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/users')
        ->assertSuccessful()
        ->assertJsonFragment([
            'id' => $visibleUser->id,
            'email' => 'visible.user@example.com',
        ])
        ->assertJsonMissing([
            'id' => $deletedUser->id,
            'email' => 'deleted.user@example.com',
        ]);
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
        'department_id' => $department->id,
        'position_id' => $position->id,
        'join_date' => now()->toDateString(),
        'base_salary' => 1000,
        'employment_status' => 'full-time',
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
        'password' => 'Secretpass-123',
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
        ->and(Hash::check('Secretpass-123', $createdUser->password))->toBeTrue()
        ->and(Activity::query()->where('log_name', 'users')->where('description', 'create')->exists())->toBeTrue();
});

test('user creation rejects multiple roles', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $this->withToken($token)->postJson('/api/users', [
        'name' => 'Multi Role User',
        'email' => 'multi.role@example.com',
        'password' => 'Secretpass-123',
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

test('deleting a user soft deletes the user and linked employee without hard deleting either record', function () {
    $targetUser = User::factory()->create([
        'name' => 'Original User',
        'email' => 'original.user@example.com',
        'status' => 'active',
    ]);
    $targetUser->assignRole('employee');
    $targetUser->createToken('target-device');

    $employee = Employee::query()->create([
        'user_id' => $targetUser->id,
        'employee_id' => 'EMP401',
        'full_name' => 'Original User',
        'join_date' => now()->toDateString(),
        'base_salary' => 1000,
        'employment_status' => 'full-time',
    ]);

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $deleteResponse = $this->withToken($token)
        ->deleteJson("/api/users/{$targetUser->id}");

    $deleteResponse
        ->assertSuccessful()
        ->assertJsonPath('message', 'User deleted successfully.')
        ->assertJsonPath('data.status', 'inactive');

    expect(User::query()->find($targetUser->id))->toBeNull()
        ->and(Employee::query()->find($employee->id))->toBeNull()
        ->and(User::withTrashed()->find($targetUser->id))->not->toBeNull()
        ->and(Employee::withTrashed()->find($employee->id))->not->toBeNull()
        ->and(User::withTrashed()->find($targetUser->id)->status)->toBe('inactive')
        ->and($targetUser->tokens()->count())->toBe(0)
        ->and(Activity::query()->where('log_name', 'users')->where('description', 'delete')->exists())->toBeTrue();
});

test('deleting an unlinked user soft deletes only the user', function () {
    $targetUser = User::factory()->create([
        'name' => 'Unlinked User',
        'email' => 'unlinked.user@example.com',
        'status' => 'active',
    ]);
    $targetUser->assignRole('employee');

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $this->withToken($token)
        ->deleteJson("/api/users/{$targetUser->id}")
        ->assertSuccessful()
        ->assertJsonPath('message', 'User deleted successfully.');

    expect(User::query()->find($targetUser->id))->toBeNull()
        ->and(User::withTrashed()->find($targetUser->id))->not->toBeNull()
        ->and(Employee::withTrashed()->where('user_id', $targetUser->id)->exists())->toBeFalse();
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

test('admin can update a permission description', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $permission = Permission::where('name', 'payrolls.approve')->firstOrFail();

    $response = $this->withToken($token)
        ->patchJson("/api/permissions/{$permission->id}", [
            'description' => 'Approve a payroll before it can be paid out',
        ]);

    $response
        ->assertSuccessful()
        ->assertJsonPath('message', 'Permission description updated successfully.')
        ->assertJsonPath('data.id', $permission->id)
        ->assertJsonPath('data.description', 'Approve a payroll before it can be paid out')
        ->assertJsonPath('data.name', 'payrolls.approve')
        ->assertJsonPath('data.module', 'payrolls');

    expect($permission->fresh()->description)->toBe('Approve a payroll before it can be paid out')
        ->and($permission->fresh()->name)->toBe('payrolls.approve')
        ->and($permission->fresh()->module)->toBe('payrolls');
});

test('updating a permission description rejects extra fields', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $permission = Permission::where('name', 'payrolls.approve')->firstOrFail();

    $this->withToken($token)
        ->patchJson("/api/permissions/{$permission->id}", [
            'description' => 'Updated',
            'name' => 'payrolls.hacked',
        ])
        ->assertSuccessful();

    expect($permission->fresh()->name)->toBe('payrolls.approve');
});

test('non-admin users cannot update a permission description', function () {
    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $permission = Permission::where('name', 'payrolls.approve')->firstOrFail();

    $this->withToken($token)
        ->patchJson("/api/permissions/{$permission->id}", [
            'description' => 'Should not be allowed',
        ])
        ->assertForbidden();

    expect($permission->fresh()->description)->not->toBe('Should not be allowed');
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

test('hr can list users but cannot access other admin user management endpoints', function () {
    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $targetUser = User::factory()->create();

    $this->withToken($token)
        ->getJson('/api/users')
        ->assertSuccessful();

    $this->withToken($token)
        ->getJson("/api/users/{$targetUser->id}")
        ->assertForbidden();

    $this->withToken($token)
        ->postJson('/api/users', [
            'name' => 'New User',
            'email' => 'new.user@example.com',
            'password' => 'Secretpass-123',
            'status' => 'active',
            'roles' => ['employee'],
        ])
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

test('hr can use the employee linking selector query on the user list', function () {
    $linkableUser = User::factory()->create([
        'name' => 'Linkable User',
        'email' => 'linkable.user@example.com',
        'status' => 'active',
    ]);
    $linkableUser->assignRole('employee');

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/users?without_employee=1&exclude_admin=1')
        ->assertSuccessful()
        ->assertJsonFragment([
            'id' => $linkableUser->id,
            'email' => 'linkable.user@example.com',
        ]);
});

test('non admin and non hr users cannot access the user list', function () {
    $employee = User::factory()->create();
    $employee->assignRole('employee');
    $token = $employee->createToken('employee-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/users')
        ->assertForbidden();
});

test('admin user dropdown search returns lightweight matches', function () {
    $alpha = User::factory()->create([
        'name' => 'Vy Rith',
        'email' => 'vy@gmail.com',
        'status' => 'active',
    ]);
    $alpha->assignRole('employee');

    $beta = User::factory()->create([
        'name' => 'Borey',
        'email' => 'borey@gmail.com',
        'status' => 'active',
    ]);
    $beta->assignRole('employee');

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/users/search?q=vy')
        ->assertSuccessful()
        ->assertJsonCount(1)
        ->assertJsonPath('0.user_id', $alpha->id)
        ->assertJsonPath('0.name', 'Vy Rith')
        ->assertJsonPath('0.email', 'vy@gmail.com')
        ->assertJsonPath('0.display', 'Vy Rith (vy@gmail.com)')
        ->assertJsonMissingPath('0.roles')
        ->assertJsonMissingPath('meta');
});

test('admin user dropdown search supports email matching and returns empty for blank or no matches', function () {
    $target = User::factory()->create([
        'name' => 'Target User',
        'email' => 'target.user@example.com',
        'status' => 'active',
    ]);
    $target->assignRole('employee');

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/users/search?q=TARGET.USER')
        ->assertSuccessful()
        ->assertJsonCount(1)
        ->assertJsonPath('0.user_id', $target->id);

    $this->withToken($token)
        ->getJson('/api/users/search?q=')
        ->assertSuccessful()
        ->assertExactJson([]);

    $this->withToken($token)
        ->getJson('/api/users/search?q=missing')
        ->assertSuccessful()
        ->assertExactJson([]);
});

test('admin user dropdown search is limited to 15 results and ordered by name', function () {
    foreach (range(1, 16) as $index) {
        $user = User::factory()->create([
            'name' => sprintf('Adam %02d', 17 - $index),
            'email' => "dropdown{$index}@example.com",
            'status' => 'active',
        ]);
        $user->assignRole('employee');
    }

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/users/search?q=adam');

    $response
        ->assertSuccessful()
        ->assertJsonCount(15)
        ->assertJsonPath('0.name', 'Adam 01')
        ->assertJsonPath('14.name', 'Adam 15');

    expect(collect($response->json())->pluck('name'))->not->toContain('Adam 16');
});

test('hr can access user dropdown search', function () {
    $alpha = User::factory()->create([
        'name' => 'Vy Rith',
        'email' => 'vy.hr@example.com',
        'status' => 'active',
    ]);
    $alpha->assignRole('employee');

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/users/search?q=vy')
        ->assertSuccessful()
        ->assertJsonCount(1)
        ->assertJsonPath('0.user_id', $alpha->id);
});

test('non admin and non hr users cannot access user dropdown search', function () {
    $employee = User::factory()->create();
    $employee->assignRole('employee');
    $token = $employee->createToken('employee-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/users/search?q=vy')
        ->assertForbidden();
});
