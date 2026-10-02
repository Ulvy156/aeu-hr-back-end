<?php

use App\Enums\JobLevel;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

test('org chart seeders create occupied seats with job levels and reporting', function () {
    $ceo = User::query()->where('email', 'tum.punleu@gmail.com')->first();
    $gm = User::query()->where('email', 'sim.sea@gmail.com')->first();
    $hr = User::query()->where('email', 'hr@gmail.com')->first();
    $admin = User::query()->where('email', 'admin@gmail.com')->first();
    $supervisor = User::query()->where('email', 'thun.chan@gmail.com')->first();

    expect($ceo)->not->toBeNull()
        ->and($ceo->hasRole('ceo'))->toBeTrue()
        ->and($ceo->employee->employee_id)->toBe('EMP-0001')
        ->and($ceo->employee->position->name)->toBe('Chief Executive Officer')
        ->and($ceo->employee->position->job_level)->toBe(JobLevel::Ceo)
        ->and($ceo->employee->manager_id)->toBeNull()
        ->and($gm)->not->toBeNull()
        ->and($gm->hasRole('gm'))->toBeTrue()
        ->and($gm->employee->position->name)->toBe('General Manager')
        ->and($gm->employee->position->job_level)->toBe(JobLevel::Gm)
        ->and($gm->employee->manager_id)->toBe($ceo->employee->id)
        ->and($hr)->not->toBeNull()
        ->and($hr->hasRole('hr'))->toBeTrue()
        ->and($hr->employee->full_name)->toBe('Kean Da')
        ->and($hr->employee->position->name)->toBe('Head of HR')
        ->and($hr->employee->position->job_level)->toBe(JobLevel::Head)
        ->and($hr->hasDirectPermission('payrolls.generate'))->toBeTrue()
        ->and($hr->employee->manager_id)->toBe($gm->employee->id)
        ->and($admin)->not->toBeNull()
        ->and($admin->hasRole('admin'))->toBeTrue()
        ->and($admin->employee)->toBeNull()
        ->and($supervisor->hasRole('employee'))->toBeTrue()
        ->and(Employee::query()->count())->toBe(20);
});

test('rerunning user seeder promotes an existing HR employee to head', function () {
    $hr = User::query()->where('email', 'hr@gmail.com')->firstOrFail();
    $juniorPosition = Position::query()->where('name', 'HR Admin')->firstOrFail();
    $hr->employee->update(['position_id' => $juniorPosition->id]);

    $this->seed(UserSeeder::class);

    $hr->refresh();
    expect($hr->employee->position->name)->toBe('Head of HR')
        ->and($hr->hasDirectPermission('payrolls.generate'))->toBeTrue();
});

test('regular HR does not inherit the head payroll permission', function () {
    $regularHr = User::factory()->create();
    $regularHr->assignRole('hr');

    expect($regularHr->hasPermissionTo('payrolls.generate'))->toBeFalse();
});

test('user seeder fills zero salaries and preserves customized salaries', function () {
    $expected = [
        'EMP-0001' => '3200.00',
        'EMP-0002' => '2200.00',
        'EMP-0003' => '900.00',
        'EMP-0004' => '350.00',
        'EMP-0009' => '650.00',
        'EMP-0017' => '700.00',
        'EMP-0018' => '450.00',
        'EMP-0019' => '500.00',
        'EMP-0020' => '1100.00',
    ];

    foreach ($expected as $employeeId => $salary) {
        expect(Employee::query()->where('employee_id', $employeeId)->value('base_salary'))->toBe($salary);
    }

    expect(Employee::query()->where('base_salary', '<=', 0)->count())->toBe(0);

    Employee::query()->where('employee_id', 'EMP-0004')->update(['base_salary' => '475.00']);
    Employee::query()->where('employee_id', 'EMP-0005')->update(['base_salary' => '0.00']);

    $this->seed(UserSeeder::class);

    expect(Employee::query()->where('employee_id', 'EMP-0004')->value('base_salary'))->toBe('475.00')
        ->and(Employee::query()->where('employee_id', 'EMP-0005')->value('base_salary'))->toBe('350.00');
});

test('vacant org chart titles are positions without employees', function () {
    $salesAdmin = Position::query()->where('name', 'Sales Admin')->first();
    $offLine = Position::query()->where('name', 'Off Line')->first();

    expect($salesAdmin)->not->toBeNull()
        ->and($salesAdmin->job_level)->toBe(JobLevel::Junior)
        ->and($salesAdmin->employees()->count())->toBe(0)
        ->and($offLine)->not->toBeNull()
        ->and($offLine->job_level)->toBe(JobLevel::Junior)
        ->and($offLine->employees()->count())->toBe(0)
        ->and(Position::query()->whereIn('name', [
            'Senior Sales Executive',
            'HR Officer',
            'Admin Officer',
            'Digital Marketing Supervisor',
            'Graphic Designer',
            'HR Manager',
        ])->exists())->toBeFalse()
        ->and(User::query()->whereIn('email', [
            'chorn.chan@gmail.com',
            'lach.sreytea@gmail.com',
            'him.kimsreng@gmail.com',
        ])->exists())->toBeFalse();
});
