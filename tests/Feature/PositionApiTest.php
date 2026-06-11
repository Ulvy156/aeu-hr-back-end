<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('hr can filter create view update and delete positions', function () {
    $this->seed(RoleSeeder::class);

    $sales = Department::query()->create([
        'name' => 'Sales',
        'status' => 'active',
    ]);

    $hrDepartment = Department::query()->create([
        'name' => 'Human Resources',
        'status' => 'active',
    ]);

    Position::query()->create([
        'name' => 'Sales Executive',
        'department_id' => $sales->id,
        'status' => 'active',
    ]);

    Position::query()->create([
        'name' => 'Recruiter',
        'department_id' => $hrDepartment->id,
        'status' => 'inactive',
    ]);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->getJson("/api/positions?department_id={$sales->id}")
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.name', 'Sales Executive');

    $createResponse = $this->withToken($token)->postJson('/api/positions', [
        'name' => 'HR Officer',
        'department_id' => $hrDepartment->id,
        'status' => 'active',
    ]);

    $createResponse
        ->assertCreated()
        ->assertJsonPath('data.department.id', $hrDepartment->id);

    $positionId = $createResponse->json('data.id');

    $this->withToken($token)
        ->getJson("/api/positions/{$positionId}")
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'HR Officer');

    $this->withToken($token)
        ->putJson("/api/positions/{$positionId}", [
            'name' => 'Senior HR Officer',
            'department_id' => $hrDepartment->id,
            'status' => 'inactive',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'Senior HR Officer')
        ->assertJsonPath('data.status', 'inactive');

    $this->withToken($token)
        ->deleteJson("/api/positions/{$positionId}")
        ->assertSuccessful()
        ->assertJsonPath('message', 'Position deleted successfully.');

    $this->assertSoftDeleted('positions', [
        'id' => $positionId,
    ]);
});

test('position deletion is rejected when employees are assigned to it', function () {
    $this->seed(RoleSeeder::class);

    $position = Position::query()->create([
        'name' => 'Accountant',
        'status' => 'active',
    ]);

    $linkedUser = User::factory()->create([
        'status' => 'active',
    ]);
    $linkedUser->assignRole('employee');

    Employee::query()->create([
        'user_id' => $linkedUser->id,
        'employee_id' => 'EMP100',
        'full_name' => 'Linked Employee',
        'position_id' => $position->id,
        'join_date' => now()->toDateString(),
        'base_salary' => 500,
        'employment_status' => 'active',
    ]);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->deleteJson("/api/positions/{$position->id}")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors('position');
});

test('employees cannot manage positions', function () {
    $this->seed(RoleSeeder::class);

    $employee = User::factory()->create();
    $employee->assignRole('employee');
    $token = $employee->createToken('employee-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/positions')
        ->assertForbidden();
});
