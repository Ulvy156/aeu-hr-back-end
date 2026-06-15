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
    Storage::fake(config('filesystems.cloud'));
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
        'gender' => 'female',
        'join_date' => '2026-05-01',
        'base_salary' => '1200.50',
        'employment_status' => 'full-time',
    ], $overrides);
}

/**
 * Create a root employee (no manager of their own) to be referenced as
 * `manager_id` by other employees in tests.
 *
 * @param  array<string, mixed>  $overrides
 */
function createManagerEmployee(array $overrides = []): Employee
{
    $managerUser = linkableUser([
        'name' => 'Default Manager',
        'email' => 'default.manager@example.com',
    ]);

    return Employee::query()->create(array_merge([
        'user_id' => $managerUser->id,
        'employee_id' => 'MGR-00001',
        'full_name' => 'Default Manager',
        'join_date' => '2026-01-01',
        'base_salary' => '2000.00',
        'employment_status' => 'full-time',
    ], $overrides));
}

test('admin and hr can create employee with a valid existing user id', function (string $role) {
    [$department, $position] = employeeDepartmentAndPosition();
    $manager = createManagerEmployee();
    $linkedUser = linkableUser([
        'name' => 'Original Linked User',
        'email' => 'original.linked@example.com',
    ]);
    $linkedUser->assignRole('employee');

    [, $token] = employeeActor($role);

    $response = $this->withToken($token)->post('/api/employees', employeePayload($linkedUser, [
        'full_name' => 'Updated Linked Employee',
        'department_id' => $department->id,
        'position_id' => $position->id,
        'manager_id' => $manager->id,
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
        ->assertJsonPath('data.user.email', 'original.linked@example.com')
        ->assertJsonPath('data.user.status', 'active')
        ->assertJsonPath('data.manager.id', $manager->id)
        ->assertJsonMissingPath('data.user.roles');

    $employee = Employee::query()->where('user_id', $linkedUser->id)->firstOrFail();

    expect($employee->employee_id)->toBe('EMP-00001')
        ->and($employee->manager_id)->toBe($manager->id)
        ->and($employee->user->name)->toBe('Updated Linked Employee')
        ->and($employee->user->email)->toBe('original.linked@example.com')
        ->and($employee->user->hasRole('employee'))->toBeTrue();

    Storage::disk(config('filesystems.cloud'))->assertExists($employee->profile_photo);
})->with(['admin', 'hr']);

test('creating a probation employee defaults probation_end_date to join_date plus the configured period', function () {
    $manager = createManagerEmployee();
    $linkedUser = linkableUser();
    [, $token] = employeeActor('hr');

    $response = $this->withToken($token)->postJson('/api/employees', employeePayload($linkedUser, [
        'employment_status' => 'probation',
        'manager_id' => $manager->id,
    ]));

    $response
        ->assertCreated()
        ->assertJsonPath('data.employment_status', 'probation')
        ->assertJsonPath('data.probation_end_date', '2026-08-01');

    $employee = Employee::query()->where('user_id', $linkedUser->id)->firstOrFail();

    expect($employee->probation_end_date->toDateString())->toBe('2026-08-01')
        ->and($employee->user->status)->toBe('active');
});

test('creating a probation employee allows hr to override probation_end_date', function () {
    $manager = createManagerEmployee();
    $linkedUser = linkableUser();
    [, $token] = employeeActor('hr');

    $response = $this->withToken($token)->postJson('/api/employees', employeePayload($linkedUser, [
        'employment_status' => 'probation',
        'probation_end_date' => '2026-06-30',
        'manager_id' => $manager->id,
    ]));

    $response
        ->assertCreated()
        ->assertJsonPath('data.probation_end_date', '2026-06-30');
});

test('employee create fails when manager_id is missing for a non-ceo user', function () {
    $linkedUser = linkableUser();
    $linkedUser->assignRole('employee');
    [, $token] = employeeActor('hr');

    $this->withToken($token)
        ->postJson('/api/employees', employeePayload($linkedUser))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors('manager_id');
});

test('employee create allows missing manager_id when the linked user holds the ceo role', function () {
    $linkedUser = linkableUser([
        'name' => 'Chief Executive',
        'email' => 'ceo.user@example.com',
    ]);
    $linkedUser->assignRole('ceo');
    [, $token] = employeeActor('admin');

    $this->withToken($token)
        ->postJson('/api/employees', employeePayload($linkedUser, [
            'full_name' => 'Chief Executive',
        ]))
        ->assertCreated()
        ->assertJsonPath('data.manager', null);

    $employee = Employee::query()->where('user_id', $linkedUser->id)->firstOrFail();

    expect($employee->manager_id)->toBeNull();
});

test('employee create fails when manager_id does not reference an existing employee', function () {
    $linkedUser = linkableUser();
    [, $token] = employeeActor('hr');

    $this->withToken($token)
        ->postJson('/api/employees', employeePayload($linkedUser, [
            'manager_id' => 999999,
        ]))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors('manager_id');
});

test('employee create fails when user id is missing', function () {
    [, $token] = employeeActor('hr');

    $this->withToken($token)
        ->postJson('/api/employees', [
            'full_name' => 'Missing User Id',
            'join_date' => '2026-05-01',
            'base_salary' => '900.00',
            'employment_status' => 'full-time',
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
        'join_date' => '2026-05-01',
        'base_salary' => '1000.00',
        'employment_status' => 'full-time',
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
        'join_date' => '2026-05-01',
        'base_salary' => '750.00',
        'employment_status' => 'full-time',
    ]);

    [, $token] = employeeActor('hr');

    $this->withToken($token)
        ->putJson("/api/employees/{$employee->id}", [
            'user_id' => $otherUser->id,
            'full_name' => 'Locked User',
            'join_date' => '2026-05-01',
            'base_salary' => '750.00',
            'employment_status' => 'full-time',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors('user_id');
});

test('employee update fails when manager_id is missing for a non-ceo employee', function () {
    [$department, $position] = employeeDepartmentAndPosition();
    $manager = createManagerEmployee();
    $linkedUser = linkableUser();

    $employee = Employee::query()->create([
        'user_id' => $linkedUser->id,
        'employee_id' => 'EMP-00001',
        'full_name' => 'Employee Without Manager',
        'department_id' => $department->id,
        'position_id' => $position->id,
        'manager_id' => $manager->id,
        'join_date' => '2026-05-01',
        'base_salary' => '1000.00',
        'employment_status' => 'full-time',
    ]);

    [, $token] = employeeActor('hr');

    $this->withToken($token)
        ->putJson("/api/employees/{$employee->id}", [
            'full_name' => $employee->full_name,
            'department_id' => $department->id,
            'position_id' => $position->id,
            'join_date' => '2026-05-01',
            'base_salary' => '1000.00',
            'employment_status' => 'full-time',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors('manager_id');
});

test('employee update allows missing manager_id for the ceo', function () {
    $linkedUser = linkableUser([
        'name' => 'Chief Executive',
        'email' => 'update.ceo@example.com',
    ]);
    $linkedUser->assignRole('ceo');

    $employee = Employee::query()->create([
        'user_id' => $linkedUser->id,
        'employee_id' => 'EMP-00001',
        'full_name' => 'Chief Executive',
        'join_date' => '2026-05-01',
        'base_salary' => '5000.00',
        'employment_status' => 'full-time',
    ]);

    [, $token] = employeeActor('admin');

    $this->withToken($token)
        ->putJson("/api/employees/{$employee->id}", [
            'full_name' => 'Chief Executive',
            'join_date' => '2026-05-01',
            'base_salary' => '5000.00',
            'employment_status' => 'full-time',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.manager', null);
});

test('employee update rejects manager_id that points to itself or creates a circular relationship', function () {
    [$department, $position] = employeeDepartmentAndPosition();
    $top = createManagerEmployee();

    $linkedUser = linkableUser([
        'name' => 'Middle Manager',
        'email' => 'middle.manager@example.com',
    ]);
    $middle = Employee::query()->create([
        'user_id' => $linkedUser->id,
        'employee_id' => 'EMP-00001',
        'full_name' => 'Middle Manager',
        'department_id' => $department->id,
        'position_id' => $position->id,
        'manager_id' => $top->id,
        'join_date' => '2026-05-01',
        'base_salary' => '1500.00',
        'employment_status' => 'full-time',
    ]);

    [, $token] = employeeActor('hr');

    // Cannot be its own manager.
    $this->withToken($token)
        ->putJson("/api/employees/{$middle->id}", [
            'full_name' => $middle->full_name,
            'department_id' => $department->id,
            'position_id' => $position->id,
            'manager_id' => $middle->id,
            'join_date' => '2026-05-01',
            'base_salary' => '1500.00',
            'employment_status' => 'full-time',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('manager_id');

    // Promoting the top manager to report to its own subordinate creates a cycle.
    $this->withToken($token)
        ->putJson("/api/employees/{$top->id}", [
            'full_name' => $top->full_name,
            'manager_id' => $middle->id,
            'join_date' => $top->join_date->toDateString(),
            'base_salary' => $top->base_salary,
            'employment_status' => 'full-time',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('manager_id');
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
        'department_id' => $department->id,
        'position_id' => $position->id,
        'join_date' => '2026-05-01',
        'base_salary' => '700.00',
        'employment_status' => 'full-time',
    ]);

    $bobUser = linkableUser([
        'name' => 'Bob Resigned',
        'email' => 'bob.resigned@example.com',
    ]);
    Employee::query()->create([
        'user_id' => $bobUser->id,
        'employee_id' => 'EMP-00002',
        'full_name' => 'Bob Resigned',
        'join_date' => '2026-05-01',
        'last_working_date' => '2026-05-10',
        'base_salary' => '900.00',
        'employment_status' => 'resigned',
    ]);

    [, $hrToken] = employeeActor('hr');

    $this->withToken($hrToken)
        ->getJson("/api/employees?department_id={$department->id}&employment_status=full-time&search=Alice")
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.employee_id', 'EMP-00001');

    [$employeeUser] = employeeActor('employee');
    Sanctum::actingAs($employeeUser);

    $this->flushHeaders()
        ->getJson('/api/employees')
        ->assertForbidden();
});

test('employee dropdown search returns lightweight matches for admin hr and ceo only', function (string $role) {
    $alphaUser = linkableUser([
        'name' => 'Vy Rith User',
        'email' => "{$role}.alpha@example.com",
    ]);
    Employee::query()->create([
        'user_id' => $alphaUser->id,
        'employee_id' => 'EMP-00100',
        'full_name' => 'Vy Rith',
        'join_date' => '2026-05-01',
        'base_salary' => '1000.00',
        'employment_status' => 'full-time',
    ]);

    $betaUser = linkableUser([
        'name' => 'Borey User',
        'email' => "{$role}.beta@example.com",
    ]);
    Employee::query()->create([
        'user_id' => $betaUser->id,
        'employee_id' => 'EMP-00101',
        'full_name' => 'Borey',
        'join_date' => '2026-05-01',
        'base_salary' => '1000.00',
        'employment_status' => 'full-time',
    ]);

    [, $token] = employeeActor($role);

    $this->withToken($token)
        ->getJson('/api/employees/search?q=vy')
        ->assertSuccessful()
        ->assertJsonCount(1)
        ->assertJsonPath('0.employee_id', 'EMP-00100')
        ->assertJsonPath('0.full_name', 'Vy Rith')
        ->assertJsonPath('0.display', 'EMP-00100 - Vy Rith')
        ->assertJsonMissingPath('0.email')
        ->assertJsonMissingPath('meta');
})->with(['admin', 'hr', 'ceo']);

test('employee dropdown search supports employee id matching case insensitively and returns empty for blank or no matches', function () {
    $linkedUser = linkableUser([
        'name' => 'Target User',
        'email' => 'target.employee.search@example.com',
    ]);
    Employee::query()->create([
        'user_id' => $linkedUser->id,
        'employee_id' => 'EMP-00999',
        'full_name' => 'Target Employee',
        'join_date' => '2026-05-01',
        'base_salary' => '1000.00',
        'employment_status' => 'full-time',
    ]);

    [, $token] = employeeActor('hr');

    $this->withToken($token)
        ->getJson('/api/employees/search?q=emp-009')
        ->assertSuccessful()
        ->assertJsonCount(1)
        ->assertJsonPath('0.employee_id', 'EMP-00999');

    $this->withToken($token)
        ->getJson('/api/employees/search?q=')
        ->assertSuccessful()
        ->assertExactJson([]);

    $this->withToken($token)
        ->getJson('/api/employees/search?q=missing')
        ->assertSuccessful()
        ->assertExactJson([]);
});

test('employee dropdown search is limited to 15 results and ordered by full name', function () {
    foreach (range(1, 16) as $index) {
        $linkedUser = linkableUser([
            'name' => "Search User {$index}",
            'email' => "search.user{$index}@example.com",
        ]);

        Employee::query()->create([
            'user_id' => $linkedUser->id,
            'employee_id' => 'EMP-'.str_pad((string) $index, 5, '0', STR_PAD_LEFT),
            'full_name' => sprintf('Aaron %02d', 17 - $index),
            'join_date' => '2026-05-01',
            'base_salary' => '1000.00',
            'employment_status' => 'full-time',
        ]);
    }

    [, $token] = employeeActor('hr');

    $response = $this->withToken($token)
        ->getJson('/api/employees/search?q=aaron');

    $response
        ->assertSuccessful()
        ->assertJsonCount(15)
        ->assertJsonPath('0.full_name', 'Aaron 01')
        ->assertJsonPath('14.full_name', 'Aaron 15');

    expect(collect($response->json())->pluck('full_name'))->not->toContain('Aaron 16');
});

test('employee dropdown search forbids employee role', function () {
    [, $token] = employeeActor('employee');

    $this->withToken($token)
        ->getJson('/api/employees/search?q=vy')
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
        'join_date' => '2026-05-01',
        'base_salary' => '1000.00',
        'employment_status' => 'full-time',
    ]);

    $deletedUser = linkableUser([
        'name' => 'Deleted Employee User',
        'email' => 'deleted.employee.user@example.com',
    ]);
    $deletedEmployee = Employee::query()->create([
        'user_id' => $deletedUser->id,
        'employee_id' => 'EMP-00002',
        'full_name' => 'Deleted Employee',
        'join_date' => '2026-05-01',
        'base_salary' => '1000.00',
        'employment_status' => 'full-time',
    ]);
    $deletedEmployee->delete();

    [, $token] = employeeActor('hr');

    $this->withToken($token)
        ->getJson('/api/employees')
        ->assertSuccessful()
        ->assertJsonFragment([
            'employee_id' => 'EMP-00001',
        ])
        ->assertJsonFragment([
            'email' => 'visible.employee.user@example.com',
        ])
        ->assertJsonMissing([
            'employee_id' => 'EMP-00002',
        ])
        ->assertJsonMissing([
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
        'join_date' => '2026-05-01',
        'base_salary' => '1000.00',
        'employment_status' => 'full-time',
        'profile_photo' => UploadedFile::fake()->image('avatar.jpg')->store('employee-profile-photos', config('filesystems.cloud')),
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

    Storage::disk(config('filesystems.cloud'))->assertMissing($employee->profile_photo);
});

test('employee update syncs linked user name and status and audits salary changes', function () {
    [$department, $position] = employeeDepartmentAndPosition();
    $manager = createManagerEmployee();
    $linkedUser = linkableUser([
        'name' => 'Original Name',
        'email' => 'original.employee@example.com',
        'status' => 'active',
    ]);

    $employee = Employee::query()->create([
        'user_id' => $linkedUser->id,
        'employee_id' => 'EMP-00001',
        'full_name' => 'Original Name',
        'department_id' => $department->id,
        'position_id' => $position->id,
        'manager_id' => $manager->id,
        'join_date' => '2026-05-01',
        'base_salary' => '1000.00',
        'employment_status' => 'full-time',
    ]);

    [, $token] = employeeActor('hr');

    $this->withToken($token)
        ->putJson("/api/employees/{$employee->id}", [
            'full_name' => 'Updated Name',
            'department_id' => $department->id,
            'position_id' => $position->id,
            'manager_id' => $manager->id,
            'join_date' => '2026-05-01',
            'last_working_date' => '2026-05-20',
            'base_salary' => '1200.50',
            'employment_status' => 'resigned',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.full_name', 'Updated Name')
        ->assertJsonPath('data.user.email', 'original.employee@example.com')
        ->assertJsonPath('data.user.status', 'inactive')
        ->assertJsonPath('data.manager.id', $manager->id)
        ->assertJsonMissingPath('data.user.roles');

    $activity = Activity::query()
        ->where('log_name', 'employees')
        ->where('description', 'update')
        ->latest('id')
        ->first();

    expect($employee->fresh()->user->email)->toBe('original.employee@example.com')
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
