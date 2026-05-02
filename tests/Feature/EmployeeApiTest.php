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

test('hr can create an employee with a backend generated employee id and profile photo', function () {
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
        'employee_id' => 'SHOULD-BE-IGNORED',
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
        ->assertJsonPath('data.employee_id', 'EMP-00001')
        ->assertJsonPath('data.user.email', 'admin.employee@example.com')
        ->assertJsonPath('data.user.roles.0', 'employee')
        ->assertJsonPath('data.department.id', $department->id)
        ->assertJsonPath('data.position.id', $position->id);

    $employee = Employee::query()->where('employee_id', 'EMP-00001')->firstOrFail();
    $employee->load('user');

    expect($employee->user->hasRole('employee'))->toBeTrue()
        ->and($employee->user->status)->toBe('active')
        ->and($employee->employee_id)->toBe('EMP-00001');

    Storage::disk('public')->assertExists($employee->profile_photo);
});

test('hr can create an employee without a profile photo', function () {
    $this->seed(RoleSeeder::class);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $response = $this->withToken($token)->postJson('/api/employees', [
        'full_name' => 'No Photo Employee',
        'email' => 'no.photo.employee@example.com',
        'password' => 'secretpass123',
        'join_date' => '2026-05-01',
        'base_salary' => '950.00',
        'employment_status' => 'active',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.employee_id', 'EMP-00001')
        ->assertJsonPath('data.profile_photo', null)
        ->assertJsonPath('data.profile_photo_url', null);
});

test('employee creation rejects invalid profile photo files', function () {
    $this->seed(RoleSeeder::class);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $response = $this->withToken($token)->post('/api/employees', [
        'full_name' => 'Invalid Photo Employee',
        'email' => 'invalid.photo.employee@example.com',
        'password' => 'secretpass123',
        'join_date' => '2026-05-01',
        'base_salary' => '850.00',
        'employment_status' => 'active',
        'profile_photo' => UploadedFile::fake()->create('document.pdf', 10, 'application/pdf'),
    ], [
        'Accept' => 'application/json',
    ]);

    $response
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors('profile_photo');
});

test('employee ids are generated sequentially and remain unique', function () {
    $this->seed(RoleSeeder::class);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $firstResponse = $this->withToken($token)->postJson('/api/employees', [
        'full_name' => 'First Employee',
        'email' => 'first.employee@example.com',
        'password' => 'secretpass123',
        'join_date' => '2026-05-01',
        'base_salary' => '800.00',
        'employment_status' => 'active',
    ]);

    $secondResponse = $this->withToken($token)->postJson('/api/employees', [
        'employee_id' => 'EMP-99999',
        'full_name' => 'Second Employee',
        'email' => 'second.employee@example.com',
        'password' => 'secretpass123',
        'join_date' => '2026-05-01',
        'base_salary' => '900.00',
        'employment_status' => 'active',
    ]);

    $firstResponse
        ->assertCreated()
        ->assertJsonPath('data.employee_id', 'EMP-00001');

    $secondResponse
        ->assertCreated()
        ->assertJsonPath('data.employee_id', 'EMP-00002');

    expect(Employee::query()->pluck('employee_id')->all())
        ->toBe(['EMP-00001', 'EMP-00002']);
});

test('employee creation enforces employment status rules', function () {
    $this->seed(RoleSeeder::class);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)->postJson('/api/employees', [
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
        'employee_id' => 'EMP-00003',
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
        'employee_id' => 'EMP-00004',
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
        ->assertJsonPath('data.0.employee_id', 'EMP-00003');

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
        'employee_id' => 'EMP-00005',
        'full_name' => 'Original Name',
        'email' => 'original.employee@example.com',
        'department_id' => $department->id,
        'position_id' => $position->id,
        'join_date' => '2026-05-01',
        'base_salary' => '1000.00',
        'employment_status' => 'active',
        'profile_photo' => 'employee-profile-photos/original-avatar.jpg',
    ]);
    Storage::disk('public')->put('employee-profile-photos/original-avatar.jpg', 'old-photo-content');

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

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
        ->assertJsonPath('data.base_salary', '1200.50');

    $activity = Activity::query()
        ->where('log_name', 'employees')
        ->where('description', 'update')
        ->latest('id')
        ->first();

    expect($employee->fresh()->user->email)->toBe('updated.employee@example.com')
        ->and($employee->fresh()->user->status)->toBe('inactive')
        ->and($employee->fresh()->employee_id)->toBe('EMP-00005')
        ->and($employee->fresh()->profile_photo)->toBe('employee-profile-photos/original-avatar.jpg')
        ->and($activity->properties->get('old_values')['base_salary'])->toBe('1000.00')
        ->and($activity->properties->get('new_values')['base_salary'])->toBe('1200.50');
});

test('employee id cannot be changed on update', function () {
    $this->seed(RoleSeeder::class);

    $linkedUser = User::query()->create([
        'name' => 'Locked Id User',
        'email' => 'locked.id@example.com',
        'password' => 'secretpass123',
        'status' => 'active',
    ]);
    $linkedUser->assignRole('employee');

    $employee = Employee::query()->create([
        'user_id' => $linkedUser->id,
        'employee_id' => 'EMP-00006',
        'full_name' => 'Locked Id User',
        'email' => 'locked.id@example.com',
        'join_date' => '2026-05-01',
        'base_salary' => '750.00',
        'employment_status' => 'active',
    ]);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->putJson("/api/employees/{$employee->id}", [
            'employee_id' => 'EMP-99999',
            'full_name' => 'Locked Id User',
            'email' => 'locked.id@example.com',
            'join_date' => '2026-05-01',
            'base_salary' => '750.00',
            'employment_status' => 'active',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors('employee_id');
});

test('updating an employee without profile photo keeps the existing photo', function () {
    $this->seed(RoleSeeder::class);

    $linkedUser = User::query()->create([
        'name' => 'Keep Photo User',
        'email' => 'keep.photo@example.com',
        'password' => 'secretpass123',
        'status' => 'active',
    ]);
    $linkedUser->assignRole('employee');

    Storage::disk('public')->put('employee-profile-photos/existing-avatar.jpg', 'existing-photo');

    $employee = Employee::query()->create([
        'user_id' => $linkedUser->id,
        'employee_id' => 'EMP-00006',
        'full_name' => 'Keep Photo User',
        'email' => 'keep.photo@example.com',
        'join_date' => '2026-05-01',
        'base_salary' => '700.00',
        'employment_status' => 'active',
        'profile_photo' => 'employee-profile-photos/existing-avatar.jpg',
    ]);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->putJson("/api/employees/{$employee->id}", [
            'full_name' => 'Keep Photo User Updated',
            'email' => 'keep.photo@example.com',
            'join_date' => '2026-05-01',
            'base_salary' => '700.00',
            'employment_status' => 'active',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.profile_photo', 'employee-profile-photos/existing-avatar.jpg');

    expect($employee->fresh()->profile_photo)->toBe('employee-profile-photos/existing-avatar.jpg');
    Storage::disk('public')->assertExists('employee-profile-photos/existing-avatar.jpg');
});

test('updating an employee with a valid new profile photo replaces the old photo', function () {
    $this->seed(RoleSeeder::class);

    $linkedUser = User::query()->create([
        'name' => 'Replace Photo User',
        'email' => 'replace.photo@example.com',
        'password' => 'secretpass123',
        'status' => 'active',
    ]);
    $linkedUser->assignRole('employee');

    Storage::disk('public')->put('employee-profile-photos/old-avatar.jpg', 'old-photo');

    $employee = Employee::query()->create([
        'user_id' => $linkedUser->id,
        'employee_id' => 'EMP-00007',
        'full_name' => 'Replace Photo User',
        'email' => 'replace.photo@example.com',
        'join_date' => '2026-05-01',
        'base_salary' => '700.00',
        'employment_status' => 'active',
        'profile_photo' => 'employee-profile-photos/old-avatar.jpg',
    ]);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $response = $this->withToken($token)->put("/api/employees/{$employee->id}", [
        'full_name' => 'Replace Photo User Updated',
        'email' => 'replace.photo@example.com',
        'join_date' => '2026-05-01',
        'base_salary' => '700.00',
        'employment_status' => 'active',
        'profile_photo' => UploadedFile::fake()->image('replacement.jpg'),
    ], [
        'Accept' => 'application/json',
    ]);

    $response
        ->assertSuccessful()
        ->assertJsonPath('data.full_name', 'Replace Photo User Updated');

    $updatedEmployee = $employee->fresh();

    expect($updatedEmployee->profile_photo)
        ->not->toBe('employee-profile-photos/old-avatar.jpg');

    Storage::disk('public')->assertMissing('employee-profile-photos/old-avatar.jpg');
    Storage::disk('public')->assertExists($updatedEmployee->profile_photo);
});

test('updating an employee with an invalid profile photo fails validation', function () {
    $this->seed(RoleSeeder::class);

    $linkedUser = User::query()->create([
        'name' => 'Invalid Update Photo User',
        'email' => 'invalid.update.photo@example.com',
        'password' => 'secretpass123',
        'status' => 'active',
    ]);
    $linkedUser->assignRole('employee');

    $employee = Employee::query()->create([
        'user_id' => $linkedUser->id,
        'employee_id' => 'EMP-00008',
        'full_name' => 'Invalid Update Photo User',
        'email' => 'invalid.update.photo@example.com',
        'join_date' => '2026-05-01',
        'base_salary' => '700.00',
        'employment_status' => 'active',
    ]);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $response = $this->withToken($token)->put("/api/employees/{$employee->id}", [
        'full_name' => 'Invalid Update Photo User',
        'email' => 'invalid.update.photo@example.com',
        'join_date' => '2026-05-01',
        'base_salary' => '700.00',
        'employment_status' => 'active',
        'profile_photo' => UploadedFile::fake()->create('bad.txt', 5, 'text/plain'),
    ], [
        'Accept' => 'application/json',
    ]);

    $response
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors('profile_photo');
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
        'employee_id' => 'EMP-00009',
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
