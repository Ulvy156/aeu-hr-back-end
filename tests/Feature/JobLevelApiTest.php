<?php

use App\Enums\JobLevel;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollBatch;
use App\Models\PayrollItem;
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

function jobLevelPayrollItem(PayrollBatch $batch, Employee $employee, string $netSalary = '100.00'): PayrollItem
{
    return PayrollItem::query()->create([
        'payroll_batch_id' => $batch->id,
        'employee_id' => $employee->id,
        'base_salary' => 1000,
        'daily_rate' => 40,
        'working_days' => 22,
        'present_days' => 22,
        'gross_salary' => 1000,
        'taxable_salary' => 1000,
        'net_salary' => $netSalary,
        'status' => 'locked',
    ]);
}

test('creating an employee with a manager position assigns the manager role', function () {
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

    expect($linkedUser->fresh()->hasRole('manager'))->toBeTrue()
        ->and($linkedUser->fresh()->hasRole('employee'))->toBeFalse();
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
        ->and($linkedUser->fresh()->hasRole('head'))->toBeFalse()
        ->and($linkedUser->fresh()->hasPermissionTo('leaves.approve_hr'))->toBeTrue()
        ->and($linkedUser->fresh()->hasPermissionTo('leaves.reject_hr'))->toBeTrue()
        ->and($linkedUser->fresh()->hasPermissionTo('payrolls.approve'))->toBeTrue()
        ->and($linkedUser->fresh()->hasPermissionTo('payrolls.reject'))->toBeTrue();
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

    expect($employeeUser->fresh()->hasRole('manager'))->toBeTrue()
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

test('a commercial department head only sees payroll and payslips for their department', function () {
    $commercial = jobLevelDepartment('Commercial');
    $marketing = jobLevelDepartment('Marketing');
    $headPosition = jobLevelPosition($commercial, 'Head of Commercial', JobLevel::Head);
    $staffPosition = jobLevelPosition($commercial, 'Sales Executive', JobLevel::Junior);
    $otherPosition = jobLevelPosition($marketing, 'Designer', JobLevel::Junior);

    $headUser = jobLevelUser('head', ['email' => 'commercial.head@example.com']);
    $headEmployee = jobLevelEmployee($headUser, [
        'department_id' => $commercial->id,
        'position_id' => $headPosition->id,
        'employee_id' => 'EMP-90001',
    ]);
    $commercialStaff = jobLevelEmployee(jobLevelUser('employee', ['email' => 'commercial.staff@example.com']), [
        'department_id' => $commercial->id,
        'position_id' => $staffPosition->id,
        'employee_id' => 'EMP-90002',
    ]);
    $marketingStaff = jobLevelEmployee(jobLevelUser('employee', ['email' => 'marketing.staff@example.com']), [
        'department_id' => $marketing->id,
        'position_id' => $otherPosition->id,
        'employee_id' => 'EMP-90003',
    ]);

    $batch = PayrollBatch::query()->create([
        'month' => 5,
        'year' => 2026,
        'status' => 'approved',
    ]);
    $ownItem = jobLevelPayrollItem($batch, $commercialStaff, '200.00');
    $otherItem = jobLevelPayrollItem($batch, $marketingStaff, '900.00');
    jobLevelPayrollItem($batch, $headEmployee, '300.00');

    Sanctum::actingAs($headUser);

    $payslipIds = collect($this->getJson('/api/payslips')->assertSuccessful()->json('data'))->pluck('id');

    expect($payslipIds)->toHaveCount(2)
        ->and($payslipIds)->toContain($ownItem->id)
        ->and($payslipIds)->not->toContain($otherItem->id);

    $this->getJson("/api/payslips/{$ownItem->id}")->assertSuccessful();
    $this->getJson("/api/payslips/{$otherItem->id}")->assertForbidden();

    $this->getJson('/api/payrolls')
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.item_count', 2)
        ->assertJsonPath('data.0.totals.net_salary', '500.00');

    $this->getJson("/api/payrolls/{$batch->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.item_count', 2)
        ->assertJsonPath('data.totals.net_salary', '500.00');
});

test('an HR department head can see payroll for every department', function () {
    $hrDepartment = jobLevelDepartment('HR & Admin');
    $commercial = jobLevelDepartment('Commercial');
    $headPosition = jobLevelPosition($hrDepartment, 'Head of HR', JobLevel::Head);
    $staffPosition = jobLevelPosition($commercial, 'Sales Executive', JobLevel::Junior);

    $headUser = jobLevelUser('head', ['email' => 'hr.head@example.com']);
    jobLevelEmployee($headUser, [
        'department_id' => $hrDepartment->id,
        'position_id' => $headPosition->id,
        'employee_id' => 'EMP-91001',
    ]);
    $commercialStaff = jobLevelEmployee(jobLevelUser('employee', ['email' => 'hrhead.commercial@example.com']), [
        'department_id' => $commercial->id,
        'position_id' => $staffPosition->id,
        'employee_id' => 'EMP-91002',
    ]);

    $batch = PayrollBatch::query()->create([
        'month' => 6,
        'year' => 2026,
        'status' => 'approved',
    ]);
    $item = jobLevelPayrollItem($batch, $commercialStaff, '900.00');

    Sanctum::actingAs($headUser);

    $this->getJson('/api/payslips')
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $item->id);

    $this->getJson("/api/payslips/{$item->id}")->assertSuccessful();
    $this->getJson('/api/payrolls')->assertSuccessful()->assertJsonPath('data.0.item_count', 1);
});

test('a general manager can see payroll and payslips for every department', function () {
    $executive = jobLevelDepartment('Executive');
    $commercial = jobLevelDepartment('Commercial');
    $gmPosition = jobLevelPosition($executive, 'General Manager', JobLevel::Gm);
    $staffPosition = jobLevelPosition($commercial, 'Sales Executive', JobLevel::Junior);

    $gmUser = jobLevelUser('gm', ['email' => 'company.gm@example.com']);
    jobLevelEmployee($gmUser, [
        'department_id' => $executive->id,
        'position_id' => $gmPosition->id,
        'employee_id' => 'EMP-92001',
    ]);
    $commercialStaff = jobLevelEmployee(jobLevelUser('employee', ['email' => 'gm.commercial@example.com']), [
        'department_id' => $commercial->id,
        'position_id' => $staffPosition->id,
        'employee_id' => 'EMP-92002',
    ]);

    $batch = PayrollBatch::query()->create([
        'month' => 7,
        'year' => 2026,
        'status' => 'approved',
    ]);
    $item = jobLevelPayrollItem($batch, $commercialStaff, '900.00');

    Sanctum::actingAs($gmUser);

    $this->getJson('/api/payslips')
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $item->id);

    $this->getJson("/api/payslips/{$item->id}")->assertSuccessful();
    $this->getJson('/api/payrolls')->assertSuccessful()->assertJsonPath('data.0.item_count', 1);
});

test('a manager cannot view payroll batches', function () {
    $department = jobLevelDepartment();
    $position = jobLevelPosition($department, 'Sales Manager', JobLevel::Manager);
    $managerUser = jobLevelUser('manager', ['email' => 'sales.manager@example.com']);
    jobLevelEmployee($managerUser, [
        'department_id' => $department->id,
        'position_id' => $position->id,
        'employee_id' => 'EMP-93001',
    ]);

    Sanctum::actingAs($managerUser);

    $this->getJson('/api/payrolls')->assertForbidden();
    $this->getJson('/api/payslips')->assertSuccessful()->assertJsonPath('meta.total', 0);
});

test('a commercial department head only sees their department in payroll reports', function () {
    $commercial = jobLevelDepartment('Commercial');
    $marketing = jobLevelDepartment('Marketing');
    $headPosition = jobLevelPosition($commercial, 'Head of Commercial', JobLevel::Head);
    $staffPosition = jobLevelPosition($commercial, 'Sales Executive', JobLevel::Junior);
    $otherPosition = jobLevelPosition($marketing, 'Designer', JobLevel::Junior);

    $headUser = jobLevelUser('head', ['email' => 'commercial.head.report@example.com']);
    jobLevelEmployee($headUser, [
        'department_id' => $commercial->id,
        'position_id' => $headPosition->id,
        'employee_id' => 'EMP-94001',
    ]);
    $commercialStaff = jobLevelEmployee(jobLevelUser('employee', ['email' => 'commercial.report@example.com']), [
        'department_id' => $commercial->id,
        'position_id' => $staffPosition->id,
        'employee_id' => 'EMP-94002',
    ]);
    $marketingStaff = jobLevelEmployee(jobLevelUser('employee', ['email' => 'marketing.report@example.com']), [
        'department_id' => $marketing->id,
        'position_id' => $otherPosition->id,
        'employee_id' => 'EMP-94003',
    ]);

    $batch = PayrollBatch::query()->create([
        'month' => 8,
        'year' => 2026,
        'status' => 'approved',
    ]);
    $ownItem = jobLevelPayrollItem($batch, $commercialStaff, '200.00');
    $otherItem = jobLevelPayrollItem($batch, $marketingStaff, '900.00');

    Sanctum::actingAs($headUser);

    $itemIds = collect($this->getJson('/api/reports/payroll?report_type=employee_list&month=8&year=2026')
        ->assertSuccessful()
        ->assertJsonPath('data.summary.item_count', 1)
        ->json('data.items'))->pluck('id');

    expect($itemIds)->toContain($ownItem->id)
        ->and($itemIds)->not->toContain($otherItem->id);
});

test('updating an employee position_id assigns the new job level role', function () {
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

    expect($linkedUser->fresh()->hasRole('manager'))->toBeTrue()
        ->and($linkedUser->fresh()->hasRole('employee'))->toBeFalse();
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
        ->and($linkedUser->fresh()->hasRole('manager'))->toBeFalse();
});

test('head of hr inherits leave and payroll approve permissions', function () {
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

    expect($linkedUser->fresh()->hasRole('head'))->toBeTrue()
        ->and($linkedUser->fresh()->hasPermissionTo('leaves.approve_hr'))->toBeTrue()
        ->and($linkedUser->fresh()->hasPermissionTo('payrolls.generate'))->toBeTrue()
        ->and($linkedUser->fresh()->hasPermissionTo('payrolls.update'))->toBeTrue()
        ->and($linkedUser->fresh()->hasPermissionTo('payrolls.submit'))->toBeTrue()
        ->and($linkedUser->fresh()->hasPermissionTo('payrolls.approve'))->toBeTrue();
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

    expect($linkedUser->fresh()->hasRole('head'))->toBeTrue()
        ->and($linkedUser->fresh()->hasPermissionTo('leaves.approve_hr'))->toBeFalse()
        ->and($linkedUser->fresh()->hasPermissionTo('payrolls.generate'))->toBeFalse()
        ->and($linkedUser->fresh()->hasPermissionTo('payrolls.approve'))->toBeFalse();
});

test('junior hr does not inherit leave or payroll approve permissions', function () {
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
        ->and($linkedUser->fresh()->hasPermissionTo('leaves.approve_hr'))->toBeFalse()
        ->and($linkedUser->fresh()->hasPermissionTo('payrolls.generate'))->toBeFalse()
        ->and($linkedUser->fresh()->hasPermissionTo('payrolls.approve'))->toBeFalse();
});
