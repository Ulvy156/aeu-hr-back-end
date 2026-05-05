<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('public');
});

function employeeActor(string $role): array
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return [$user, $user->createToken("{$role}-device")->plainTextToken];
}

function linkableUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'name' => 'Linked User',
        'email' => 'linked.user@example.com',
        'status' => 'active',
    ], $overrides));
}

function employeeDepartmentAndPosition(): array
{
    $department = Department::query()->create([
        'name' => 'Finance',
        'status' => 'active',
    ]);

    $position = Position::query()->create([
        'name' => 'Accountant',
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    return [$department, $position];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function employeePayload(User $linkedUser, array $overrides = []): array
{
    return array_merge([
        'user_id' => $linkedUser->id,
        'full_name' => 'Employee Profile',
        'email' => 'employee.profile@example.com',
        'gender' => 'female',
        'join_date' => '2026-05-01',
        'base_salary' => '1200.50',
        'employment_status' => 'active',
    ], $overrides);
}

test('admin and hr can create employee with a valid existing user id', function (string $role) {
    [$department, $position] = employeeDepartmentAndPosition();
    $linkedUser = linkableUser([
        'name' => 'Original Linked User',
        'email' => 'original.linked@example.com',
    ]);
    $linkedUser->assignRole('employee');

    [, $token] = employeeActor($role);

    $response = $this->withToken($token)->post('/api/employees', employeePayload($linkedUser, [
        'full_name' => 'Updated Linked Employee',
        'email' => 'updated.linked@example.com',
        'department_id' => $department->id,
        'position_id' => $position->id,
        'profile_photo' => UploadedFile::fake()->image('avatar.jpg'),
    ]), [
        'Accept' => 'application/json',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('message', 'Employee created successfully.')
        ->assertJsonPath('data.employee_id', 'EMP-00001')
        ->assertJsonPath('data.user.id', $linkedUser->id)
        ->assertJsonPath('data.user.name', 'Updated Linked Employee')
        ->assertJsonPath('data.user.email', 'updated.linked@example.com')
        ->assertJsonPath('data.user.status', 'active')
        ->assertJsonMissingPath('data.user.roles');

    $employee = Employee::query()->where('user_id', $linkedUser->id)->firstOrFail();

    expect($employee->employee_id)->toBe('EMP-00001')
        ->and($employee->user->name)->toBe('Updated Linked Employee')
        ->and($employee->user->email)->toBe('updated.linked@example.com')
        ->and($employee->user->hasRole('employee'))->toBeTrue();

    Storage::disk('public')->assertExists($employee->profile_photo);
})->with(['admin', 'hr']);

test('employee create fails when user id is missing', function () {
    [, $token] = employeeActor('hr');

    $this->withToken($token)
        ->postJson('/api/employees', [
            'full_name' => 'Missing User Id',
            'email' => 'missing.user.id@example.com',
            'join_date' => '2026-05-01',
            'base_salary' => '900.00',
            'employment_status' => 'active',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors('user_id');
});

test('employee create fails when user id does not exist', function () {
    [, $token] = employeeActor('hr');

    $this->withToken($token)
        ->postJson('/api/employees', employeePayload(new User(['id' => 999999]), [
            'user_id' => 999999,
        ]))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors('user_id');
});

test('employee create fails when selected user already has an employee profile', function () {
    $linkedUser = linkableUser();
    Employee::query()->create([
        'user_id' => $linkedUser->id,
        'employee_id' => 'EMP-00001',
        'full_name' => 'Existing Employee',
        'email' => $linkedUser->email,
        'join_date' => '2026-05-01',
        'base_salary' => '1000.00',
        'employment_status' => 'active',
    ]);

    [, $token] = employeeActor('hr');

    $this->withToken($token)
        ->postJson('/api/employees', employeePayload($linkedUser))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors('user_id');
});

test('employee create rejects password because the user must already exist', function () {
    $linkedUser = linkableUser();
    [, $token] = employeeActor('hr');

    $this->withToken($token)
        ->postJson('/api/employees', employeePayload($linkedUser, [
            'password' => 'secretpass123',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');
});

test('employee update cannot change user id', function () {
    $linkedUser = linkableUser([
        'email' => 'locked.user@example.com',
    ]);
    $otherUser = linkableUser([
        'name' => 'Other User',
        'email' => 'other.user@example.com',
    ]);

    $employee = Employee::query()->create([
        'user_id' => $linkedUser->id,
        'employee_id' => 'EMP-00001',
        'full_name' => 'Locked User',
        'email' => 'locked.user@example.com',
        'join_date' => '2026-05-01',
        'base_salary' => '750.00',
        'employment_status' => 'active',
    ]);

    [, $token] = employeeActor('hr');

    $this->withToken($token)
        ->putJson("/api/employees/{$employee->id}", [
            'user_id' => $otherUser->id,
            'full_name' => 'Locked User',
            'email' => 'locked.user@example.com',
            'join_date' => '2026-05-01',
            'base_salary' => '750.00',
            'employment_status' => 'active',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors('user_id');
});

test('normal employee cannot create employee profile', function () {
    $linkedUser = linkableUser();
    [, $token] = employeeActor('employee');

    $this->withToken($token)
        ->postJson('/api/employees', employeePayload($linkedUser))
        ->assertForbidden();
});

test('employee response includes only minimal linked user info', function () {
    $linkedUser = linkableUser([
        'name' => 'Minimal User',
        'email' => 'minimal.user@example.com',
        'status' => 'inactive',
    ]);

    $employee = Employee::query()->create([
        'user_id' => $linkedUser->id,
        'employee_id' => 'EMP-00001',
        'full_name' => 'Minimal Employee',
        'email' => 'minimal.user@example.com',
        'join_date' => '2026-05-01',
        'base_salary' => '900.00',
        'employment_status' => 'terminated',
        'last_working_date' => '2026-05-31',
    ]);

    [, $token] = employeeActor('hr');

    $this->withToken($token)
        ->getJson("/api/employees/{$employee->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.user.id', $linkedUser->id)
        ->assertJsonPath('data.user.name', 'Minimal User')
        ->assertJsonPath('data.user.email', 'minimal.user@example.com')
        ->assertJsonPath('data.user.status', 'inactive')
        ->assertJsonMissingPath('data.user.roles')
        ->assertJsonMissingPath('data.user.password')
        ->assertJsonMissingPath('data.user.remember_token')
        ->assertJsonMissingPath('data.user.email_verified_at');
});

test('employee list filtering works for hr and employees cannot access employee list', function () {
    [$department, $position] = employeeDepartmentAndPosition();

    $aliceUser = linkableUser([
        'name' => 'Alice Active',
        'email' => 'alice.active@example.com',
    ]);
    Employee::query()->create([
        'user_id' => $aliceUser->id,
        'employee_id' => 'EMP-00001',
        'full_name' => 'Alice Active',
        'email' => 'alice.active@example.com',
        'department_id' => $department->id,
        'position_id' => $position->id,
        'join_date' => '2026-05-01',
        'base_salary' => '700.00',
        'employment_status' => 'active',
    ]);

    $bobUser = linkableUser([
        'name' => 'Bob Resigned',
        'email' => 'bob.resigned@example.com',
    ]);
    Employee::query()->create([
        'user_id' => $bobUser->id,
        'employee_id' => 'EMP-00002',
        'full_name' => 'Bob Resigned',
        'email' => 'bob.resigned@example.com',
        'join_date' => '2026-05-01',
        'last_working_date' => '2026-05-10',
        'base_salary' => '900.00',
        'employment_status' => 'resigned',
    ]);

    [, $hrToken] = employeeActor('hr');

    $this->withToken($hrToken)
        ->getJson("/api/employees?department_id={$department->id}&employment_status=active&search=Alice")
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.employee_id', 'EMP-00001');

    [$employeeUser] = employeeActor('employee');
    Sanctum::actingAs($employeeUser);

    $this->flushHeaders()
        ->getJson('/api/employees')
        ->assertForbidden();
});

test('default employee list excludes soft deleted employees', function () {
    $visibleUser = linkableUser([
        'name' => 'Visible Employee User',
        'email' => 'visible.employee.user@example.com',
    ]);
    Employee::query()->create([
        'user_id' => $visibleUser->id,
        'employee_id' => 'EMP-00001',
        'full_name' => 'Visible Employee',
        'email' => 'visible.employee.user@example.com',
        'join_date' => '2026-05-01',
        'base_salary' => '1000.00',
        'employment_status' => 'active',
    ]);

    $deletedUser = linkableUser([
        'name' => 'Deleted Employee User',
        'email' => 'deleted.employee.user@example.com',
    ]);
    $deletedEmployee = Employee::query()->create([
        'user_id' => $deletedUser->id,
        'employee_id' => 'EMP-00002',
        'full_name' => 'Deleted Employee',
        'email' => 'deleted.employee.user@example.com',
        'join_date' => '2026-05-01',
        'base_salary' => '1000.00',
        'employment_status' => 'active',
    ]);
    $deletedEmployee->delete();

    [, $token] = employeeActor('hr');

    $this->withToken($token)
        ->getJson('/api/employees')
        ->assertSuccessful()
        ->assertJsonFragment([
            'employee_id' => 'EMP-00001',
            'email' => 'visible.employee.user@example.com',
        ])
        ->assertJsonMissing([
            'employee_id' => 'EMP-00002',
            'email' => 'deleted.employee.user@example.com',
        ]);
});

test('deleting an employee soft deletes the employee and linked user without hard deleting either record', function () {
    $linkedUser = linkableUser([
        'name' => 'Delete Target User',
        'email' => 'delete.target@example.com',
        'status' => 'active',
    ]);
    $linkedUser->assignRole('employee');

    $employee = Employee::query()->create([
        'user_id' => $linkedUser->id,
        'employee_id' => 'EMP-00001',
        'full_name' => 'Delete Target Employee',
        'email' => 'delete.target@example.com',
        'join_date' => '2026-05-01',
        'base_salary' => '1000.00',
        'employment_status' => 'active',
        'profile_photo' => UploadedFile::fake()->image('avatar.jpg')->store('employee-profile-photos', 'public'),
    ]);

    [, $token] = employeeActor('hr');

    $this->withToken($token)
        ->deleteJson("/api/employees/{$employee->id}")
        ->assertSuccessful()
        ->assertJsonPath('message', 'Employee deleted successfully.')
        ->assertJsonPath('data', null);

    expect(Employee::query()->find($employee->id))->toBeNull()
        ->and(User::query()->find($linkedUser->id))->toBeNull()
        ->and(Employee::withTrashed()->find($employee->id))->not->toBeNull()
        ->and(User::withTrashed()->find($linkedUser->id))->not->toBeNull()
        ->and(User::withTrashed()->find($linkedUser->id)->status)->toBe('inactive')
        ->and(Activity::query()->where('log_name', 'employees')->where('description', 'delete')->exists())->toBeTrue();

    Storage::disk('public')->assertMissing($employee->profile_photo);
});

test('employee update syncs linked user fields and audits salary changes', function () {
    [$department, $position] = employeeDepartmentAndPosition();
    $linkedUser = linkableUser([
        'name' => 'Original Name',
        'email' => 'original.employee@example.com',
        'status' => 'active',
    ]);

    $employee = Employee::query()->create([
        'user_id' => $linkedUser->id,
        'employee_id' => 'EMP-00001',
        'full_name' => 'Original Name',
        'email' => 'original.employee@example.com',
        'department_id' => $department->id,
        'position_id' => $position->id,
        'join_date' => '2026-05-01',
        'base_salary' => '1000.00',
        'employment_status' => 'active',
    ]);

    [, $token] = employeeActor('hr');

    $this->withToken($token)
        ->putJson("/api/employees/{$employee->id}", [
            'full_name' => 'Updated Name',
            'email' => 'updated.employee@example.com',
            'department_id' => $department->id,
            'position_id' => $position->id,
            'join_date' => '2026-05-01',
            'last_working_date' => '2026-05-20',
            'base_salary' => '1200.50',
            'employment_status' => 'resigned',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.full_name', 'Updated Name')
        ->assertJsonPath('data.user.email', 'updated.employee@example.com')
        ->assertJsonPath('data.user.status', 'inactive')
        ->assertJsonMissingPath('data.user.roles');

    $activity = Activity::query()
        ->where('log_name', 'employees')
        ->where('description', 'update')
        ->latest('id')
        ->first();

    expect($employee->fresh()->user->email)->toBe('updated.employee@example.com')
        ->and($employee->fresh()->user->status)->toBe('inactive')
        ->and($activity->properties->get('old_values')['base_salary'])->toBe('1000.00')
        ->and($activity->properties->get('new_values')['base_salary'])->toBe('1200.50');
});

test('employee creation rejects invalid profile photo files', function () {
    $linkedUser = linkableUser();
    [, $token] = employeeActor('hr');

    $response = $this->withToken($token)->post('/api/employees', employeePayload($linkedUser, [
        'profile_photo' => UploadedFile::fake()->create('document.pdf', 10, 'application/pdf'),
    ]), [
        'Accept' => 'application/json',
    ]);

    $response
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors('profile_photo');
});
