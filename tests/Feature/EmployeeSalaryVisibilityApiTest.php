<?php

use App\Enums\JobLevel;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentHistory;
use App\Models\Position;
use App\Models\User;
use App\Services\JobLevelRoleService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function salaryVisibilityEmployee(array $overrides = []): Employee
{
    static $counter = 0;
    $counter++;

    $linkedUser = User::factory()->create([
        'name' => "Salary Employee {$counter}",
        'email' => "salary.employee{$counter}@example.com",
        'status' => 'active',
    ]);
    $linkedUser->assignRole('employee');

    return Employee::query()->create(array_merge([
        'user_id' => $linkedUser->id,
        'employee_id' => 'SAL-'.str_pad((string) $counter, 4, '0', STR_PAD_LEFT),
        'full_name' => "Salary Employee {$counter}",
        'join_date' => '2026-01-01',
        'base_salary' => '1000.00',
        'employment_status' => 'full-time',
    ], $overrides));
}

/**
 * @return array{0: User, 1: Employee, 2: Department, 3: Position}
 */
function salaryVisibilityLeader(JobLevel $level, Department $department): array
{
    $position = Position::query()->create([
        'name' => "{$level->value} seat",
        'department_id' => $department->id,
        'job_level' => $level,
        'status' => 'active',
    ]);
    $user = User::factory()->create(['status' => 'active'])->assignRole('employee');
    $employee = salaryVisibilityEmployee([
        'user_id' => $user->id,
        'full_name' => $user->name,
        'department_id' => $department->id,
        'position_id' => $position->id,
        'base_salary' => '2500.00',
    ]);
    app(JobLevelRoleService::class)->syncForEmployee($employee);

    return [$user->fresh(), $employee->fresh(), $department, $position];
}

test('head can list and view same-department salaries only', function () {
    $commercial = Department::query()->create(['name' => 'Commercial', 'status' => 'active']);
    $marketing = Department::query()->create(['name' => 'Marketing', 'status' => 'active']);

    [$headUser] = salaryVisibilityLeader(JobLevel::Head, $commercial);

    $sameDept = salaryVisibilityEmployee([
        'full_name' => 'Same Dept Peer',
        'department_id' => $commercial->id,
        'base_salary' => '800.00',
    ]);
    $otherDept = salaryVisibilityEmployee([
        'full_name' => 'Other Dept Peer',
        'department_id' => $marketing->id,
        'base_salary' => '900.00',
    ]);

    Sanctum::actingAs($headUser);

    $list = $this->getJson('/api/employees')->assertSuccessful()->json('data');
    $listedIds = collect($list)->pluck('id')->all();

    expect($listedIds)->toContain($sameDept->id)
        ->and($listedIds)->not->toContain($otherDept->id);

    $sameShow = $this->getJson("/api/employees/{$sameDept->id}")->assertSuccessful()->json('data');
    expect($sameShow)->toHaveKey('base_salary')
        ->and($sameShow['base_salary'])->toBe('800.00');

    $this->getJson("/api/employees/{$otherDept->id}")->assertForbidden();
});

test('head cannot update salaries', function () {
    $department = Department::query()->create(['name' => 'Commercial', 'status' => 'active']);
    [$headUser, $headEmployee] = salaryVisibilityLeader(JobLevel::Head, $department);
    $peer = salaryVisibilityEmployee([
        'department_id' => $department->id,
        'manager_id' => $headEmployee->id,
        'base_salary' => '800.00',
    ]);

    Sanctum::actingAs($headUser);

    expect($headUser->hasPermissionTo('employees.update_salary'))->toBeFalse()
        ->and($headUser->hasPermissionTo('employees.update'))->toBeFalse();

    $this->putJson("/api/employees/{$peer->id}", [
        'full_name' => $peer->full_name,
        'department_id' => $department->id,
        'manager_id' => $headEmployee->id,
        'join_date' => $peer->join_date->toDateString(),
        'base_salary' => '1200.00',
        'employment_status' => 'full-time',
    ])->assertForbidden();

    expect($peer->fresh()->base_salary)->toBe('800.00');
});

test('gm can view salaries across departments', function () {
    $commercial = Department::query()->create(['name' => 'Commercial', 'status' => 'active']);
    $marketing = Department::query()->create(['name' => 'Marketing', 'status' => 'active']);

    [$gmUser] = salaryVisibilityLeader(JobLevel::Gm, $commercial);
    $otherDept = salaryVisibilityEmployee([
        'full_name' => 'Marketing Peer',
        'department_id' => $marketing->id,
        'base_salary' => '950.00',
    ]);

    Sanctum::actingAs($gmUser);

    $this->getJson('/api/employees')->assertSuccessful()
        ->assertJsonFragment(['id' => $otherDept->id]);

    $this->getJson("/api/employees/{$otherDept->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.base_salary', '950.00');
});

test('ceo and hr can view all employee salaries', function (string $role) {
    $department = Department::query()->create(['name' => 'Commercial', 'status' => 'active']);
    $employee = salaryVisibilityEmployee([
        'department_id' => $department->id,
        'base_salary' => '1100.00',
    ]);
    $actor = User::factory()->create()->assignRole($role);

    Sanctum::actingAs($actor);

    $this->getJson("/api/employees/{$employee->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.base_salary', '1100.00');
})->with(['ceo', 'hr']);

test('demoting head or gm revokes employee salary grants', function (JobLevel $level) {
    $department = Department::query()->create(['name' => 'Commercial', 'status' => 'active']);
    [$user, $employee, , $position] = salaryVisibilityLeader($level, $department);

    expect($user->hasPermissionTo('employees.view_salary'))->toBeTrue()
        ->and($user->hasPermissionTo('employees.view_any'))->toBeTrue();

    $position->update(['job_level' => JobLevel::Junior]);
    app(JobLevelRoleService::class)->syncForPosition($position->fresh());

    $user = $user->fresh();
    expect($user->hasPermissionTo('employees.view_salary'))->toBeFalse()
        ->and($user->hasPermissionTo('employees.view_any'))->toBeFalse()
        ->and($user->hasPermissionTo('employees.view'))->toBeFalse();

    Sanctum::actingAs($user);
    $this->getJson('/api/employees')->assertForbidden();
    $this->getJson("/api/employees/{$employee->id}")->assertForbidden();
})->with([JobLevel::Head, JobLevel::Gm]);

test('employment history hides base salary rows without view_salary', function () {
    $department = Department::query()->create(['name' => 'Commercial', 'status' => 'active']);
    $user = User::factory()->create()->assignRole('employee');
    $user->givePermissionTo(['employees.view', 'employees.view_any']);

    $subject = salaryVisibilityEmployee([
        'department_id' => $department->id,
        'base_salary' => '700.00',
    ]);

    EmploymentHistory::query()->create([
        'employee_id' => $subject->id,
        'field' => EmploymentHistory::FIELD_BASE_SALARY,
        'old_value' => ['value' => 600],
        'new_value' => ['value' => 700],
        'effective_date' => '2026-02-01',
        'changed_by' => $user->id,
        'source' => 'correction',
    ]);
    EmploymentHistory::query()->create([
        'employee_id' => $subject->id,
        'field' => EmploymentHistory::FIELD_EMPLOYMENT_STATUS,
        'old_value' => ['value' => 'probation'],
        'new_value' => ['value' => 'full-time'],
        'effective_date' => '2026-03-01',
        'changed_by' => $user->id,
        'source' => 'update',
    ]);

    Sanctum::actingAs($user);

    $history = $this->getJson("/api/employees/{$subject->id}/employment-history")
        ->assertSuccessful()
        ->json('data');

    expect(collect($history)->pluck('field')->all())->toBe([EmploymentHistory::FIELD_EMPLOYMENT_STATUS])
        ->and($this->getJson("/api/employees/{$subject->id}")->json('data'))->not->toHaveKey('base_salary');
});
