<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('unauthenticated users cannot access the profile endpoint', function () {
    $this->getJson('/api/profile')
        ->assertUnauthorized()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Unauthenticated.');
});

test('authenticated users can view their own profile with a linked employee record', function () {
    $department = Department::query()->create([
        'name' => 'IT',
        'status' => 'active',
    ]);

    $position = Position::query()->create([
        'name' => 'Developer',
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    $user = User::factory()->create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'status' => 'active',
    ]);
    $user->assignRole('employee');
    $user->createToken('existing-device');

    Employee::query()->create([
        'user_id' => $user->id,
        'employee_id' => 'EMP001',
        'full_name' => 'John Doe',
        'gender' => 'male',
        'date_of_birth' => '2000-01-01',
        'phone_number' => '012345678',
        'email' => 'john@example.com',
        'address' => 'Phnom Penh',
        'department_id' => $department->id,
        'position_id' => $position->id,
        'join_date' => '2024-01-01',
        'base_salary' => 1200,
        'employment_status' => 'active',
    ]);

    $otherUser = User::factory()->create([
        'name' => 'Other User',
        'email' => 'other@example.com',
    ]);
    $otherUser->assignRole('hr');

    $token = $user->createToken('profile-device')->plainTextToken;

    $response = $this->withToken($token)->getJson("/api/profile?user_id={$otherUser->id}");

    $response
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Profile fetched successfully.')
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.name', 'John Doe')
        ->assertJsonPath('data.email', 'john@example.com')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.roles.0', 'employee')
        ->assertJsonPath('data.employee.employee_id', 'EMP001')
        ->assertJsonPath('data.employee.full_name', 'John Doe')
        ->assertJsonPath('data.employee.department.name', 'IT')
        ->assertJsonPath('data.employee.position.name', 'Developer')
        ->assertJsonPath('data.employee.join_date', '2024-01-01')
        ->assertJsonPath('data.employee.last_working_date', null)
        ->assertJsonPath('data.employee.employment_status', 'active')
        ->assertJsonPath('data.employee.profile_photo_url', null)
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.remember_token')
        ->assertJsonMissingPath('data.tokens')
        ->assertJsonMissingPath('data.employee.base_salary');

    expect($response->json('data.permissions'))
        ->toContain('attendance.clock_in', 'leaves.create', 'payslips.view_own')
        ->not->toContain('roles_permissions.manage');
});

test('users without an employee profile still receive their own account profile', function () {
    $user = User::factory()->create([
        'name' => 'HR User',
        'email' => 'hr@example.com',
        'status' => 'active',
    ]);
    $user->assignRole('hr');

    $token = $user->createToken('profile-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/profile')
        ->assertSuccessful()
        ->assertJsonPath('message', 'Profile fetched successfully.')
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', 'hr@example.com')
        ->assertJsonPath('data.roles.0', 'hr')
        ->assertJsonPath('data.employee', null)
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.remember_token')
        ->assertJsonMissingPath('data.tokens');
});
