<?php

use App\Enums\JobLevel;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
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
        ->and($hr->employee->position->name)->toBe('HR Admin')
        ->and($hr->employee->manager_id)->toBe($gm->employee->id)
        ->and($admin)->not->toBeNull()
        ->and($admin->hasRole('admin'))->toBeTrue()
        ->and($admin->employee)->toBeNull()
        ->and($supervisor->hasRole('employee'))->toBeTrue()
        ->and(Employee::query()->count())->toBe(20);
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
