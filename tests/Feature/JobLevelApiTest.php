<?php

use App\Enums\JobLevel;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Storage::fake(config('filesystems.cloud'));
});

function jobLevelDepartment(string $name = 'Commercial'): Department
{
    return Department::query()->create([
        'name' => $name,
        'status' => 'active',
    ]);
}

function jobLevelPosition(Department $department, string $name, JobLevel $jobLevel): Position
{
    return Position::query()->create([
        'name' => $name,
        'department_id' => $department->id,
        'job_level' => $jobLevel->value,
        'status' => 'active',
    ]);
}

function jobLevelUser(string $role, array $userOverrides = []): User
{
    $user = User::factory()->create(array_merge([
        'status' => 'active',
    ], $userOverrides));
    $user->assignRole($role);

    return $user;
}

function jobLevelEmployee(User $user, array $overrides = []): Employee
{
    return Employee::query()->create(array_merge([
        'user_id' => $user->id,
        'employee_id' => 'EMP-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
        'full_name' => $user->name,
        'join_date' => '2026-01-01',
        'base_salary' => 1000,
        'employment_status' => 'full-time',
    ], $overrides));
}

test('creating an employee with a manager position keeps the employee role', function () {
    $department = jobLevelDepartment();
    $position = jobLevelPosition($department, 'Sales Manager', JobLevel::Manager);
    $manager = jobLevelEmployee(jobLevelUser('employee', ['email' => 'root.manager@example.com']));
    $linkedUser = jobLevelUser('employee', ['email' => 'new.manager@example.com']);
    $hr = jobLevelUser('hr', ['email' => 'hr.joblevel@example.com']);

    $this->withToken($hr->createToken('hr-device')->plainTextToken)
        ->post('/api/employees', [
            'user_id' => $linkedUser->id,
            'full_name' => 'New Manager',
            'department_id' => $department->id,
            'position_id' => $position->id,
            'manager_id' => $manager->id,
            'join_date' => '2026-05-01',
            'base_salary' => '1500.00',
            'employment_status' => 'full-time',
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.position.job_level', 'manager');

    expect($linkedUser->fresh()->hasRole('employee'))->toBeTrue();
});

test('creating an employee with a supervisor position keeps the employee role', function () {
    $department = jobLevelDepartment();
    $position = jobLevelPosition($department, 'Sales Supervisor', JobLevel::Supervisor);
    $manager = jobLevelEmployee(jobLevelUser('employee', ['email' => 'sup.root@example.com']));
    $linkedUser = jobLevelUser('employee', ['email' => 'new.supervisor@example.com']);
    $hr = jobLevelUser('hr', ['email' => 'hr.supervisor@example.com']);

    $this->withToken($hr->createToken('hr-device')->plainTextToken)
        ->post('/api/employees', [
            'user_id' => $linkedUser->id,
            'full_name' => 'New Supervisor',
            'department_id' => $department->id,
            'position_id' => $position->id,
            'manager_id' => $manager->id,
            'join_date' => '2026-05-01',
            'base_salary' => '1200.00',
            'employment_status' => 'full-time',
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.position.job_level', 'supervisor');

    expect($linkedUser->fresh()->hasRole('employee'))->toBeTrue();
});

test('creating an employee on a manager position does not overwrite the hr role', function () {
    $department = jobLevelDepartment('HR & Admin');
    $position = jobLevelPosition($department, 'HR Head', JobLevel::Head);
    $manager = jobLevelEmployee(jobLevelUser('employee', ['email' => 'hr.root@example.com']));
    $linkedUser = jobLevelUser('hr', ['email' => 'protected.hr@example.com']);
    $actor = jobLevelUser('hr', ['email' => 'hr.actor@example.com']);

    $this->withToken($actor->createToken('hr-device')->plainTextToken)
        ->post('/api/employees', [
            'user_id' => $linkedUser->id,
            'full_name' => 'Protected HR',
            'department_id' => $department->id,
            'position_id' => $position->id,
            'manager_id' => $manager->id,
            'join_date' => '2026-05-01',
            'base_salary' => '1200.00',
            'employment_status' => 'full-time',
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    expect($linkedUser->fresh()->hasRole('hr'))->toBeTrue()
        ->and($linkedUser->fresh()->getRoleNames()->all())->toBe(['hr'])
        ->and($linkedUser->fresh()->hasPermissionTo('leaves.approve_hr'))->toBeTrue()
        ->and($linkedUser->fresh()->hasPermissionTo('leaves.reject_hr'))->toBeTrue()
        ->and($linkedUser->fresh()->hasPermissionTo('payrolls.submit'))->toBeTrue()
        ->and($linkedUser->fresh()->hasPermissionTo('payrolls.delete'))->toBeTrue()
        ->and($linkedUser->fresh()->hasPermissionTo('payrolls.approve'))->toBeFalse()
        ->and($linkedUser->fresh()->hasPermissionTo('payrolls.reject'))->toBeFalse();
});

test('changing a position job_level syncs assigned users except protected roles', function () {
    $department = jobLevelDepartment();
    $position = jobLevelPosition($department, 'Team Lead', JobLevel::Junior);
    $employeeUser = jobLevelUser('employee', ['email' => 'promoted.user@example.com']);
    $hrUser = jobLevelUser('hr', ['email' => 'protected.on.position@example.com']);
    jobLevelEmployee($employeeUser, [
        'department_id' => $department->id,
        'position_id' => $position->id,
        'employee_id' => 'EMP-70001',
    ]);
    jobLevelEmployee($hrUser, [
        'department_id' => $department->id,
        'position_id' => $position->id,
        'employee_id' => 'EMP-70002',
    ]);

    $hr = jobLevelUser('hr', ['email' => 'hr.promote@example.com']);

    $this->withToken($hr->createToken('hr-device')->plainTextToken)
        ->putJson("/api/positions/{$position->id}", [
            'name' => 'Team Lead',
            'department_id' => $department->id,
            'job_level' => 'manager',
            'status' => 'active',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.job_level', 'manager');

    expect($employeeUser->fresh()->hasRole('employee'))->toBeTrue()
        ->and($hrUser->fresh()->hasRole('hr'))->toBeTrue();
});

test('employees can be filtered by job_level', function () {
    $department = jobLevelDepartment();
    $junior = jobLevelPosition($department, 'Sales Executive', JobLevel::Junior);
    $supervisor = jobLevelPosition($department, 'Sales Supervisor', JobLevel::Supervisor);
    jobLevelEmployee(jobLevelUser('employee', ['email' => 'junior.filter@example.com']), [
        'full_name' => 'Junior Staff',
        'department_id' => $department->id,
        'position_id' => $junior->id,
        'employee_id' => 'EMP-80001',
    ]);
    jobLevelEmployee(jobLevelUser('employee', ['email' => 'supervisor.filter@example.com']), [
        'full_name' => 'Supervisor Staff',
        'department_id' => $department->id,
        'position_id' => $supervisor->id,
        'employee_id' => 'EMP-80002',
    ]);

    $hr = jobLevelUser('hr', ['email' => 'hr.filter@example.com']);

    $this->withToken($hr->createToken('hr-device')->plainTextToken)
        ->getJson('/api/employees?job_level=supervisor')
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.full_name', 'Supervisor Staff')
        ->assertJsonPath('data.0.position.job_level', 'supervisor');
});

test('a manager cannot view payroll batches', function () {
    $department = jobLevelDepartment();
    $position = jobLevelPosition($department, 'Sales Manager', JobLevel::Manager);
    $managerUser = jobLevelUser('employee', ['email' => 'sales.manager@example.com']);
    jobLevelEmployee($managerUser, [
        'department_id' => $department->id,
        'position_id' => $position->id,
        'employee_id' => 'EMP-93001',
    ]);

    Sanctum::actingAs($managerUser);

    $this->getJson('/api/payrolls')->assertForbidden();
    $this->getJson('/api/payslips')->assertSuccessful()->assertJsonPath('meta.total', 0);
});

test('updating an employee position_id keeps the employee access role', function () {
    $department = jobLevelDepartment();
    $junior = jobLevelPosition($department, 'Sales Executive', JobLevel::Junior);
    $managerPosition = jobLevelPosition($department, 'Sales Manager', JobLevel::Manager);
    $manager = jobLevelEmployee(jobLevelUser('employee', ['email' => 'update.root@example.com']));
    $linkedUser = jobLevelUser('employee', ['email' => 'promoted.employee@example.com']);
    $employee = jobLevelEmployee($linkedUser, [
        'department_id' => $department->id,
        'position_id' => $junior->id,
        'manager_id' => $manager->id,
        'employee_id' => 'EMP-95001',
    ]);
    $hr = jobLevelUser('hr', ['email' => 'hr.position.change@example.com']);

    $this->withToken($hr->createToken('hr-device')->plainTextToken)
        ->putJson("/api/employees/{$employee->id}", [
            'full_name' => $employee->full_name,
            'department_id' => $department->id,
            'position_id' => $managerPosition->id,
            'manager_id' => $manager->id,
            'join_date' => '2026-01-01',
            'base_salary' => '1000.00',
            'employment_status' => 'full-time',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.position.job_level', 'manager');

    expect($linkedUser->fresh()->hasRole('employee'))->toBeTrue();
});

test('updating an employee without changing position_id does not overwrite the current role', function () {
    $department = jobLevelDepartment();
    $managerPosition = jobLevelPosition($department, 'Sales Manager', JobLevel::Manager);
    $manager = jobLevelEmployee(jobLevelUser('employee', ['email' => 'keep.root@example.com']));
    $linkedUser = jobLevelUser('employee', ['email' => 'keep.employee@example.com']);
    $employee = jobLevelEmployee($linkedUser, [
        'department_id' => $department->id,
        'position_id' => $managerPosition->id,
        'manager_id' => $manager->id,
        'employee_id' => 'EMP-95002',
        'full_name' => 'Keep Role',
    ]);
    $hr = jobLevelUser('hr', ['email' => 'hr.keep.role@example.com']);

    $this->withToken($hr->createToken('hr-device')->plainTextToken)
        ->putJson("/api/employees/{$employee->id}", [
            'full_name' => 'Renamed Employee',
            'department_id' => $department->id,
            'position_id' => $managerPosition->id,
            'manager_id' => $manager->id,
            'join_date' => '2026-01-01',
            'base_salary' => '1000.00',
            'employment_status' => 'full-time',
        ])
        ->assertSuccessful();

    expect($linkedUser->fresh()->hasRole('employee'))->toBeTrue()
        ->and($linkedUser->fresh()->getRoleNames()->all())->toBe(['employee']);
});

test('a head of HR title does not grant HR access to an employee', function () {
    $department = jobLevelDepartment('HR & Admin');
    $position = jobLevelPosition($department, 'Head of HR Inherit', JobLevel::Head);
    $manager = jobLevelEmployee(jobLevelUser('employee', ['email' => 'hrhead.root@example.com']));
    $linkedUser = jobLevelUser('employee', ['email' => 'new.hr.head@example.com']);
    $hr = jobLevelUser('hr', ['email' => 'hr.inherit.actor@example.com']);

    $this->withToken($hr->createToken('hr-device')->plainTextToken)
        ->post('/api/employees', [
            'user_id' => $linkedUser->id,
            'full_name' => 'HR Head',
            'department_id' => $department->id,
            'position_id' => $position->id,
            'manager_id' => $manager->id,
            'join_date' => '2026-05-01',
            'base_salary' => '1500.00',
            'employment_status' => 'full-time',
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    expect($linkedUser->fresh()->hasRole('employee'))->toBeTrue()
        ->and($linkedUser->fresh()->hasPermissionTo('leaves.approve_hr'))->toBeFalse()
        ->and($linkedUser->fresh()->hasPermissionTo('payrolls.generate'))->toBeFalse()
        ->and($linkedUser->fresh()->hasPermissionTo('payrolls.update'))->toBeFalse()
        ->and($linkedUser->fresh()->hasPermissionTo('payrolls.submit'))->toBeFalse()
        ->and($linkedUser->fresh()->hasPermissionTo('payrolls.delete'))->toBeFalse()
        ->and($linkedUser->fresh()->hasPermissionTo('payrolls.approve'))->toBeFalse()
        ->and($linkedUser->fresh()->hasPermissionTo('payrolls.reject'))->toBeFalse();
});

test('commercial head does not inherit hr leave or payroll approve permissions', function () {
    $department = jobLevelDepartment('Commercial');
    $position = jobLevelPosition($department, 'Head of Commercial Inherit', JobLevel::Head);
    $manager = jobLevelEmployee(jobLevelUser('employee', ['email' => 'commercial.head.root@example.com']));
    $linkedUser = jobLevelUser('employee', ['email' => 'new.commercial.head@example.com']);
    $hr = jobLevelUser('hr', ['email' => 'hr.commercial.inherit@example.com']);

    $this->withToken($hr->createToken('hr-device')->plainTextToken)
        ->post('/api/employees', [
            'user_id' => $linkedUser->id,
            'full_name' => 'Commercial Head',
            'department_id' => $department->id,
            'position_id' => $position->id,
            'manager_id' => $manager->id,
            'join_date' => '2026-05-01',
            'base_salary' => '1500.00',
            'employment_status' => 'full-time',
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    expect($linkedUser->fresh()->hasRole('employee'))->toBeTrue()
        ->and($linkedUser->fresh()->hasPermissionTo('leaves.approve_hr'))->toBeFalse()
        ->and($linkedUser->fresh()->hasPermissionTo('payrolls.generate'))->toBeFalse()
        ->and($linkedUser->fresh()->hasPermissionTo('payrolls.approve'))->toBeFalse();
});

test('junior HR receives HR preparation permissions but cannot approve payroll', function () {
    $department = jobLevelDepartment('HR & Admin');
    $position = jobLevelPosition($department, 'HR Officer Inherit', JobLevel::Junior);
    $manager = jobLevelEmployee(jobLevelUser('employee', ['email' => 'junior.hr.root@example.com']));
    $linkedUser = jobLevelUser('hr', ['email' => 'junior.hr.staff@example.com']);
    $actor = jobLevelUser('hr', ['email' => 'hr.junior.actor@example.com']);

    $this->withToken($actor->createToken('hr-device')->plainTextToken)
        ->post('/api/employees', [
            'user_id' => $linkedUser->id,
            'full_name' => 'HR Officer',
            'department_id' => $department->id,
            'position_id' => $position->id,
            'manager_id' => $manager->id,
            'join_date' => '2026-05-01',
            'base_salary' => '1000.00',
            'employment_status' => 'full-time',
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    expect($linkedUser->fresh()->hasRole('hr'))->toBeTrue()
        ->and($linkedUser->fresh()->hasPermissionTo('leaves.approve_hr'))->toBeTrue()
        ->and($linkedUser->fresh()->hasPermissionTo('payrolls.generate'))->toBeTrue()
        ->and($linkedUser->fresh()->hasPermissionTo('payrolls.approve'))->toBeFalse();
});
