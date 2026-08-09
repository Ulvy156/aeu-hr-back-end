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

test('creating an intern employee defaults intern_end_date to join_date plus the configured period', function () {
    $manager = createManagerEmployee();
    $linkedUser = linkableUser();
    [, $token] = employeeActor('hr');

    $response = $this->withToken($token)->postJson('/api/employees', employeePayload($linkedUser, [
        'employment_status' => 'intern',
        'manager_id' => $manager->id,
    ]));

    $response
        ->assertCreated()
        ->assertJsonPath('data.employment_status', 'intern')
        ->assertJsonPath('data.intern_end_date', '2026-08-01');

    $employee = Employee::query()->where('user_id', $linkedUser->id)->firstOrFail();

    expect($employee->intern_end_date->toDateString())->toBe('2026-08-01')
        ->and($employee->user->status->value)->toBe('active');
});

test('creating an intern employee allows hr to override intern_end_date', function () {
    $manager = createManagerEmployee();
    $linkedUser = linkableUser();
    [, $token] = employeeActor('hr');

    $response = $this->withToken($token)->postJson('/api/employees', employeePayload($linkedUser, [
        'employment_status' => 'intern',
        'intern_end_date' => '2026-06-30',
        'manager_id' => $manager->id,
    ]));

    $response
        ->assertCreated()
        ->assertJsonPath('data.intern_end_date', '2026-06-30');
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

test('employee create fails when date_of_birth is less than 18 years ago', function () {
    $linkedUser = linkableUser();
    $manager = createManagerEmployee();
    [, $token] = employeeActor('hr');

    $this->withToken($token)
        ->postJson('/api/employees', employeePayload($linkedUser, [
            'manager_id' => $manager->id,
            'date_of_birth' => now()->subYears(17)->toDateString(),
        ]))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors('date_of_birth');
});

test('employee create succeeds when date_of_birth is exactly 18 years ago', function () {
    $linkedUser = linkableUser();
    $manager = createManagerEmployee();
    [, $token] = employeeActor('hr');

    $this->withToken($token)
        ->postJson('/api/employees', employeePayload($linkedUser, [
            'manager_id' => $manager->id,
            'date_of_birth' => now()->subYears(18)->toDateString(),
        ]))
        ->assertCreated();
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

// ──────────────────────────────────────────────────────────────────
// Employee Documents
// ──────────────────────────────────────────────────────────────────

test('admin and hr can create employee with up to 5 documents', function (string $role) {
    $manager = createManagerEmployee();
    $linkedUser = linkableUser(['email' => 'docs@example.com']);
    $linkedUser->assignRole('employee');
    [, $token] = employeeActor($role);

    $documents = [
        UploadedFile::fake()->create('id_card.pdf', 500, 'application/pdf'),
        UploadedFile::fake()->create('contract.pdf', 1000, 'application/pdf'),
        UploadedFile::fake()->image('cert.jpg', 100, 100),
        UploadedFile::fake()->create('letter.doc', 200, 'application/msword'),
        UploadedFile::fake()->create('appendix.docx', 300, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
    ];

    $response = $this->withToken($token)->post('/api/employees', array_merge(
        employeePayload($linkedUser, ['manager_id' => $manager->id]),
        ['documents' => $documents],
    ), ['Accept' => 'application/json']);

    $response
        ->assertCreated()
        ->assertJsonCount(5, 'data.documents');

    $employee = Employee::query()->where('user_id', $linkedUser->id)->firstOrFail();

    expect($employee->documents)->toHaveCount(5);

    $disk = Storage::disk(config('filesystems.cloud'));
    foreach ($employee->documents as $doc) {
        $disk->assertExists($doc['path']);
        expect($doc)->toHaveKeys(['path', 'name', 'size']);
    }
})->with(['admin', 'hr']);

test('employee creation succeeds without documents', function () {
    $manager = createManagerEmployee();
    $linkedUser = linkableUser(['email' => 'nodocs@example.com']);
    [, $token] = employeeActor('hr');

    $response = $this->withToken($token)->postJson('/api/employees', employeePayload($linkedUser, [
        'manager_id' => $manager->id,
    ]));

    $response
        ->assertCreated()
        ->assertJsonPath('data.documents', []);
});

test('employee creation fails with more than 5 documents', function () {
    $linkedUser = linkableUser(['email' => 'toomany@example.com']);
    [, $token] = employeeActor('hr');

    $documents = [];
    for ($i = 0; $i < 6; $i++) {
        $documents[] = UploadedFile::fake()->create("doc{$i}.pdf", 100, 'application/pdf');
    }

    $response = $this->withToken($token)->post('/api/employees', array_merge(
        employeePayload($linkedUser),
        ['documents' => $documents],
    ), ['Accept' => 'application/json']);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors('documents');
});

test('employee creation fails when total document size exceeds 20MB', function () {
    $linkedUser = linkableUser(['email' => 'bigdocs@example.com']);
    [, $token] = employeeActor('hr');

    $documents = [
        UploadedFile::fake()->create('big1.pdf', 11000, 'application/pdf'),
        UploadedFile::fake()->create('big2.pdf', 11000, 'application/pdf'),
    ];

    $response = $this->withToken($token)->post('/api/employees', array_merge(
        employeePayload($linkedUser),
        ['documents' => $documents],
    ), ['Accept' => 'application/json']);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors('documents');
});

test('employee creation fails with invalid document file type', function () {
    $linkedUser = linkableUser(['email' => 'badtype@example.com']);
    [, $token] = employeeActor('hr');

    $response = $this->withToken($token)->post('/api/employees', array_merge(
        employeePayload($linkedUser),
        ['documents' => [UploadedFile::fake()->create('virus.exe', 100, 'application/x-msdownload')]],
    ), ['Accept' => 'application/json']);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors('documents.0');
});

test('employee update can add documents to an existing employee', function () {
    $manager = createManagerEmployee();
    $linkedUser = linkableUser(['email' => 'updatedocs@example.com']);
    $linkedUser->assignRole('employee');
    [, $token] = employeeActor('hr');

    $employee = Employee::query()->create([
        'user_id' => $linkedUser->id,
        'employee_id' => 'EMP-00001',
        'full_name' => 'Doc Test',
        'join_date' => '2026-05-01',
        'base_salary' => '1000.00',
        'employment_status' => 'full-time',
        'manager_id' => $manager->id,
    ]);

    $response = $this->withToken($token)->post("/api/employees/{$employee->id}", [
        '_method' => 'PUT',
        'full_name' => 'Doc Test',
        'join_date' => '2026-05-01',
        'base_salary' => '1000.00',
        'employment_status' => 'full-time',
        'manager_id' => $manager->id,
        'documents' => [
            UploadedFile::fake()->create('contract.pdf', 500, 'application/pdf'),
            UploadedFile::fake()->create('id.pdf', 300, 'application/pdf'),
        ],
    ], ['Accept' => 'application/json']);

    $response
        ->assertOk()
        ->assertJsonCount(2, 'data.documents');

    $employee->refresh();
    expect($employee->documents)->toHaveCount(2);
});

test('employee update can remove specific documents by index', function () {
    $manager = createManagerEmployee();
    $linkedUser = linkableUser(['email' => 'removedocs@example.com']);
    $linkedUser->assignRole('employee');
    [, $token] = employeeActor('hr');

    $disk = Storage::disk(config('filesystems.cloud'));
    $path0 = $disk->putFile('employee-documents', UploadedFile::fake()->create('doc0.pdf', 100, 'application/pdf'));
    $path1 = $disk->putFile('employee-documents', UploadedFile::fake()->create('doc1.pdf', 100, 'application/pdf'));
    $path2 = $disk->putFile('employee-documents', UploadedFile::fake()->create('doc2.pdf', 100, 'application/pdf'));

    $employee = Employee::query()->create([
        'user_id' => $linkedUser->id,
        'employee_id' => 'EMP-00001',
        'full_name' => 'Remove Doc Test',
        'join_date' => '2026-05-01',
        'base_salary' => '1000.00',
        'employment_status' => 'full-time',
        'manager_id' => $manager->id,
        'documents' => [
            ['path' => $path0, 'name' => 'doc0.pdf', 'size' => 102400],
            ['path' => $path1, 'name' => 'doc1.pdf', 'size' => 102400],
            ['path' => $path2, 'name' => 'doc2.pdf', 'size' => 102400],
        ],
    ]);

    $response = $this->withToken($token)->putJson("/api/employees/{$employee->id}", [
        'full_name' => 'Remove Doc Test',
        'join_date' => '2026-05-01',
        'base_salary' => '1000.00',
        'employment_status' => 'full-time',
        'manager_id' => $manager->id,
        'remove_documents' => [1],
    ]);

    $response->assertOk()->assertJsonCount(2, 'data.documents');

    $employee->refresh();
    expect($employee->documents)->toHaveCount(2)
        ->and($employee->documents[0]['name'])->toBe('doc0.pdf')
        ->and($employee->documents[1]['name'])->toBe('doc2.pdf');

    $disk->assertMissing($path1);
    $disk->assertExists($path0);
    $disk->assertExists($path2);
});

test('employee update can simultaneously remove and add documents', function () {
    $manager = createManagerEmployee();
    $linkedUser = linkableUser(['email' => 'swapDocs@example.com']);
    $linkedUser->assignRole('employee');
    [, $token] = employeeActor('hr');

    $disk = Storage::disk(config('filesystems.cloud'));
    $oldPath = $disk->putFile('employee-documents', UploadedFile::fake()->create('old.pdf', 100, 'application/pdf'));

    $employee = Employee::query()->create([
        'user_id' => $linkedUser->id,
        'employee_id' => 'EMP-00001',
        'full_name' => 'Swap Doc Test',
        'join_date' => '2026-05-01',
        'base_salary' => '1000.00',
        'employment_status' => 'full-time',
        'manager_id' => $manager->id,
        'documents' => [
            ['path' => $oldPath, 'name' => 'old.pdf', 'size' => 102400],
        ],
    ]);

    $response = $this->withToken($token)->post("/api/employees/{$employee->id}", [
        '_method' => 'PUT',
        'full_name' => 'Swap Doc Test',
        'join_date' => '2026-05-01',
        'base_salary' => '1000.00',
        'employment_status' => 'full-time',
        'manager_id' => $manager->id,
        'remove_documents' => [0],
        'documents' => [
            UploadedFile::fake()->create('new1.pdf', 200, 'application/pdf'),
            UploadedFile::fake()->create('new2.pdf', 200, 'application/pdf'),
        ],
    ], ['Accept' => 'application/json']);

    $response->assertOk()->assertJsonCount(2, 'data.documents');

    $employee->refresh();
    expect($employee->documents)->toHaveCount(2)
        ->and($employee->documents[0]['name'])->toBe('new1.pdf')
        ->and($employee->documents[1]['name'])->toBe('new2.pdf');

    $disk->assertMissing($oldPath);
});

test('employee update rejects out-of-bounds remove_documents index', function () {
    $manager = createManagerEmployee();
    $linkedUser = linkableUser(['email' => 'oob@example.com']);
    $linkedUser->assignRole('employee');
    [, $token] = employeeActor('hr');

    $employee = Employee::query()->create([
        'user_id' => $linkedUser->id,
        'employee_id' => 'EMP-00001',
        'full_name' => 'OOB Test',
        'join_date' => '2026-05-01',
        'base_salary' => '1000.00',
        'employment_status' => 'full-time',
        'manager_id' => $manager->id,
        'documents' => [
            ['path' => 'employee-documents/test.pdf', 'name' => 'test.pdf', 'size' => 1024],
        ],
    ]);

    $response = $this->withToken($token)->putJson("/api/employees/{$employee->id}", [
        'full_name' => 'OOB Test',
        'join_date' => '2026-05-01',
        'base_salary' => '1000.00',
        'employment_status' => 'full-time',
        'manager_id' => $manager->id,
        'remove_documents' => [5],
    ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors('remove_documents');
});

test('employee update rejects total documents exceeding 5 after removal and addition', function () {
    $manager = createManagerEmployee();
    $linkedUser = linkableUser(['email' => 'maxdocs@example.com']);
    $linkedUser->assignRole('employee');
    [, $token] = employeeActor('hr');

    $employee = Employee::query()->create([
        'user_id' => $linkedUser->id,
        'employee_id' => 'EMP-00001',
        'full_name' => 'Max Doc Test',
        'join_date' => '2026-05-01',
        'base_salary' => '1000.00',
        'employment_status' => 'full-time',
        'manager_id' => $manager->id,
        'documents' => [
            ['path' => 'a.pdf', 'name' => 'a.pdf', 'size' => 1024],
            ['path' => 'b.pdf', 'name' => 'b.pdf', 'size' => 1024],
            ['path' => 'c.pdf', 'name' => 'c.pdf', 'size' => 1024],
            ['path' => 'd.pdf', 'name' => 'd.pdf', 'size' => 1024],
            ['path' => 'e.pdf', 'name' => 'e.pdf', 'size' => 1024],
        ],
    ]);

    $response = $this->withToken($token)->post("/api/employees/{$employee->id}", [
        '_method' => 'PUT',
        'full_name' => 'Max Doc Test',
        'join_date' => '2026-05-01',
        'base_salary' => '1000.00',
        'employment_status' => 'full-time',
        'manager_id' => $manager->id,
        'remove_documents' => [0],
        'documents' => [
            UploadedFile::fake()->create('new1.pdf', 100, 'application/pdf'),
            UploadedFile::fake()->create('new2.pdf', 100, 'application/pdf'),
        ],
    ], ['Accept' => 'application/json']);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors('documents');
});

test('deleting an employee removes documents from storage', function () {
    $manager = createManagerEmployee();
    $linkedUser = linkableUser(['email' => 'deldocs@example.com']);
    $linkedUser->assignRole('employee');
    [$actor, $token] = employeeActor('admin');

    $disk = Storage::disk(config('filesystems.cloud'));
    $path = $disk->putFile('employee-documents', UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf'));

    $employee = Employee::query()->create([
        'user_id' => $linkedUser->id,
        'employee_id' => 'EMP-00001',
        'full_name' => 'Del Doc Test',
        'join_date' => '2026-05-01',
        'base_salary' => '1000.00',
        'employment_status' => 'full-time',
        'manager_id' => $manager->id,
        'documents' => [
            ['path' => $path, 'name' => 'contract.pdf', 'size' => 102400],
        ],
    ]);

    $disk->assertExists($path);

    $this->withToken($token)->deleteJson("/api/employees/{$employee->id}")->assertOk();

    $disk->assertMissing($path);
});
