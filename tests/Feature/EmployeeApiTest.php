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
    Storage::fake('public');
});

test('hr can create an employee with a linked user account and profile photo', function () {
    $this->seed(RoleSeeder::class);

    $department = Department::query()->create([
        'name' => 'Finance',
        'status' => 'active',
    ]);

    $position = Position::query()->create([
        'name' => 'Accountant',
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $response = $this->withToken($token)->post('/api/employees', [
        'employee_id' => 'EMP001',
        'full_name' => 'Admin User',
        'email' => 'admin.employee@example.com',
        'password' => 'secretpass123',
        'gender' => 'female',
        'department_id' => $department->id,
        'position_id' => $position->id,
        'join_date' => '2026-05-01',
        'base_salary' => '1200.50',
        'employment_status' => 'active',
        'profile_photo' => UploadedFile::fake()->image('avatar.jpg'),
    ], [
        'Accept' => 'application/json',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('message', 'Employee created successfully.')
        ->assertJsonPath('data.employee_id', 'EMP001')
        ->assertJsonPath('data.user.email', 'admin.employee@example.com')
        ->assertJsonPath('data.user.roles.0', 'employee')
        ->assertJsonPath('data.department.id', $department->id)
        ->assertJsonPath('data.position.id', $position->id);

    $employee = Employee::query()->where('employee_id', 'EMP001')->firstOrFail();
    $employee->load('user');

    expect($employee->user->hasRole('employee'))->toBeTrue()
        ->and($employee->user->status)->toBe('active');

    Storage::disk('public')->assertExists($employee->profile_photo);
});

test('employee creation enforces employment status rules', function () {
    $this->seed(RoleSeeder::class);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)->postJson('/api/employees', [
        'employee_id' => 'EMP002',
        'full_name' => 'Invalid Employee',
        'email' => 'invalid.employee@example.com',
        'password' => 'secretpass123',
        'join_date' => '2026-05-01',
        'last_working_date' => '2026-05-05',
        'base_salary' => '800.00',
        'employment_status' => 'active',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors('last_working_date');
});

test('hr can filter employees and employees cannot access the employee list', function () {
    $this->seed(RoleSeeder::class);

    $department = Department::query()->create([
        'name' => 'Operations',
        'status' => 'active',
    ]);

    $position = Position::query()->create([
        'name' => 'Officer',
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    $firstUser = User::factory()->create();
    $firstUser->assignRole('employee');
    Employee::query()->create([
        'user_id' => $firstUser->id,
        'employee_id' => 'EMP003',
        'full_name' => 'Alice Active',
        'email' => 'alice.active@example.com',
        'department_id' => $department->id,
        'position_id' => $position->id,
        'join_date' => '2026-05-01',
        'base_salary' => '700.00',
        'employment_status' => 'active',
    ]);

    $secondUser = User::factory()->create();
    $secondUser->assignRole('employee');
    Employee::query()->create([
        'user_id' => $secondUser->id,
        'employee_id' => 'EMP004',
        'full_name' => 'Bob Resigned',
        'email' => 'bob.resigned@example.com',
        'join_date' => '2026-05-01',
        'last_working_date' => '2026-05-10',
        'base_salary' => '900.00',
        'employment_status' => 'resigned',
    ]);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $hrToken = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($hrToken)
        ->getJson("/api/employees?department_id={$department->id}&employment_status=active&search=Alice")
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.employee_id', 'EMP003');

    $employeeViewer = User::factory()->create();
    $employeeViewer->assignRole('employee');
    Sanctum::actingAs($employeeViewer);

    $this->flushHeaders()
        ->getJson('/api/employees')
        ->assertForbidden();
});

test('employee updates sync the linked user and audit salary changes', function () {
    $this->seed(RoleSeeder::class);

    $department = Department::query()->create([
        'name' => 'Engineering',
        'status' => 'active',
    ]);

    $position = Position::query()->create([
        'name' => 'Developer',
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    $linkedUser = User::query()->create([
        'name' => 'Original Name',
        'email' => 'original.employee@example.com',
        'password' => 'secretpass123',
        'status' => 'active',
    ]);
    $linkedUser->assignRole('employee');

    $employee = Employee::query()->create([
        'user_id' => $linkedUser->id,
        'employee_id' => 'EMP005',
        'full_name' => 'Original Name',
        'email' => 'original.employee@example.com',
        'department_id' => $department->id,
        'position_id' => $position->id,
        'join_date' => '2026-05-01',
        'base_salary' => '1000.00',
        'employment_status' => 'active',
    ]);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->putJson("/api/employees/{$employee->id}", [
            'employee_id' => 'EMP005',
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
        ->assertJsonPath('data.base_salary', '1200.50');

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

test('employee deletion soft deletes the employee record and disables the linked user', function () {
    $this->seed(RoleSeeder::class);

    $linkedUser = User::query()->create([
        'name' => 'Delete Me',
        'email' => 'delete.employee@example.com',
        'password' => 'secretpass123',
        'status' => 'active',
    ]);
    $linkedUser->assignRole('employee');

    $employee = Employee::query()->create([
        'user_id' => $linkedUser->id,
        'employee_id' => 'EMP006',
        'full_name' => 'Delete Me',
        'email' => 'delete.employee@example.com',
        'join_date' => '2026-05-01',
        'base_salary' => '650.00',
        'employment_status' => 'active',
    ]);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->deleteJson("/api/employees/{$employee->id}")
        ->assertSuccessful()
        ->assertJsonPath('message', 'Employee deleted successfully.');

    $this->assertSoftDeleted('employees', [
        'id' => $employee->id,
    ]);

    expect($linkedUser->fresh()->status)->toBe('inactive');
});
