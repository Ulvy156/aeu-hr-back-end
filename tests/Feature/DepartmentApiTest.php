<?php

use App\Models\Department;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('hr can list create view update and delete departments', function () {
    $this->seed(RoleSeeder::class);

    Department::query()->create([
        'name' => 'Operations',
        'status' => 'active',
    ]);

    Department::query()->create([
        'name' => 'Archived',
        'status' => 'inactive',
    ]);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/departments?status=active&per_page=1')
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.name', 'Operations');

    $createResponse = $this->withToken($token)->postJson('/api/departments', [
        'name' => 'Finance',
        'status' => 'active',
    ]);

    $createResponse
        ->assertCreated()
        ->assertJsonPath('message', 'Department created successfully.')
        ->assertJsonPath('data.name', 'Finance');

    $departmentId = $createResponse->json('data.id');

    $this->withToken($token)
        ->getJson("/api/departments/{$departmentId}")
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'Finance');

    $this->withToken($token)
        ->putJson("/api/departments/{$departmentId}", [
            'name' => 'Finance and Admin',
            'status' => 'inactive',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'Finance and Admin')
        ->assertJsonPath('data.status', 'inactive');

    $this->withToken($token)
        ->deleteJson("/api/departments/{$departmentId}")
        ->assertSuccessful()
        ->assertJsonPath('message', 'Department deleted successfully.');

    $this->assertSoftDeleted('departments', [
        'id' => $departmentId,
    ]);
});

test('department deletion is rejected when the department still has related records', function () {
    $this->seed(RoleSeeder::class);

    $department = Department::query()->create([
        'name' => 'Engineering',
        'status' => 'active',
    ]);

    $department->positions()->create([
        'name' => 'Backend Engineer',
        'status' => 'active',
    ]);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->deleteJson("/api/departments/{$department->id}")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors('department');
});

test('employees cannot manage departments', function () {
    $this->seed(RoleSeeder::class);

    $employee = User::factory()->create();
    $employee->assignRole('employee');
    $token = $employee->createToken('employee-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/departments')
        ->assertForbidden();
});

test('ceo can list departments but cannot create them', function () {
    $this->seed(RoleSeeder::class);

    Department::query()->create([
        'name' => 'Executive',
        'status' => 'active',
    ]);

    $ceo = User::factory()->create();
    $ceo->assignRole('ceo');
    $token = $ceo->createToken('ceo-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/departments?per_page=1')
        ->assertSuccessful()
        ->assertJsonPath('data.0.name', 'Executive');

    $this->withToken($token)
        ->postJson('/api/departments', [
            'name' => 'New Dept',
            'status' => 'active',
        ])
        ->assertForbidden();
});

test('admin and hr can list departments used by attendance and recruitment filters', function (string $role) {
    $this->seed(RoleSeeder::class);

    Department::query()->create([
        'name' => 'Filter Dept',
        'status' => 'active',
    ]);

    $user = User::factory()->create();
    $user->assignRole($role);
    $token = $user->createToken("{$role}-device")->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/departments?per_page=1')
        ->assertSuccessful()
        ->assertJsonPath('data.0.name', 'Filter Dept');
})->with(['admin', 'hr']);
