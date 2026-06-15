<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentHistory;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function historyActor(string $role): array
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return [$user, $user->createToken("{$role}-device")->plainTextToken];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function historyEmployee(array $overrides = []): Employee
{
    $linkedUser = User::factory()->create([
        'name' => 'History Employee',
        'email' => 'history.'.uniqid().'@example.com',
        'status' => 'active',
    ]);

    return Employee::query()->create(array_merge([
        'user_id' => $linkedUser->id,
        'employee_id' => 'EMP-00001',
        'full_name' => 'History Employee',
        'join_date' => '2026-05-01',
        'base_salary' => '1200.50',
        'employment_status' => 'active',
    ], $overrides));
}

test('hr can list an employees employment history ordered by most recent effective date first', function () {
    $deptA = Department::query()->create(['name' => 'Sales', 'status' => 'active']);
    $deptB = Department::query()->create(['name' => 'Engineering', 'status' => 'active']);

    $employee = historyEmployee();

    [$actor, $token] = historyActor('hr');

    EmploymentHistory::query()->create([
        'employee_id' => $employee->id,
        'field' => EmploymentHistory::FIELD_DEPARTMENT_ID,
        'old_value' => ['id' => $deptA->id, 'name' => 'Sales'],
        'new_value' => ['id' => $deptB->id, 'name' => 'Engineering'],
        'effective_date' => '2026-06-01',
        'changed_by' => $actor->id,
    ]);

    EmploymentHistory::query()->create([
        'employee_id' => $employee->id,
        'field' => EmploymentHistory::FIELD_BASE_SALARY,
        'old_value' => ['value' => 1000.0],
        'new_value' => ['value' => 1500.0],
        'effective_date' => '2026-07-01',
        'changed_by' => $actor->id,
    ]);

    $response = $this->withToken($token)
        ->getJson("/api/employees/{$employee->id}/employment-history")
        ->assertSuccessful()
        ->assertJsonPath('message', 'Employment history fetched successfully.')
        ->assertJsonPath('meta.total', 2);

    $data = $response->json('data');

    expect($data[0]['field'])->toBe(EmploymentHistory::FIELD_BASE_SALARY)
        ->and($data[0]['effective_date'])->toBe('2026-07-01')
        ->and($data[0]['changed_by']['id'])->toBe($actor->id)
        ->and($data[1]['field'])->toBe(EmploymentHistory::FIELD_DEPARTMENT_ID)
        ->and($data[1]['effective_date'])->toBe('2026-06-01');
});

test('employment history can be filtered by field and effective date range', function () {
    $deptA = Department::query()->create(['name' => 'Sales', 'status' => 'active']);
    $deptB = Department::query()->create(['name' => 'Engineering', 'status' => 'active']);

    $employee = historyEmployee();

    [$actor, $token] = historyActor('hr');

    EmploymentHistory::query()->create([
        'employee_id' => $employee->id,
        'field' => EmploymentHistory::FIELD_DEPARTMENT_ID,
        'old_value' => ['id' => $deptA->id, 'name' => 'Sales'],
        'new_value' => ['id' => $deptB->id, 'name' => 'Engineering'],
        'effective_date' => '2026-06-01',
        'changed_by' => $actor->id,
    ]);

    EmploymentHistory::query()->create([
        'employee_id' => $employee->id,
        'field' => EmploymentHistory::FIELD_BASE_SALARY,
        'old_value' => ['value' => 1000.0],
        'new_value' => ['value' => 1500.0],
        'effective_date' => '2026-07-01',
        'changed_by' => $actor->id,
    ]);

    $this->withToken($token)
        ->getJson("/api/employees/{$employee->id}/employment-history?field=base_salary")
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.field', EmploymentHistory::FIELD_BASE_SALARY);

    $this->withToken($token)
        ->getJson("/api/employees/{$employee->id}/employment-history?date_from=2026-07-01")
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.effective_date', '2026-07-01');
});

test('viewing employment history requires the employees.view permission', function (string $role, int $status) {
    $employee = historyEmployee();

    [, $token] = historyActor($role);

    $this->withToken($token)
        ->getJson("/api/employees/{$employee->id}/employment-history")
        ->assertStatus($status);
})->with([
    'admin' => ['admin', 200],
    'hr' => ['hr', 200],
    'employee' => ['employee', 403],
]);

test('unauthenticated users cannot view employment history', function () {
    $employee = historyEmployee();

    $this->getJson("/api/employees/{$employee->id}/employment-history")
        ->assertStatus(401);
});
