<?php

use App\Models\CompanySetting;
use App\Services\CompanySettingService;
use Database\Seeders\CompanySettingSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('company setting seeder creates the default singleton row', function () {
    $this->seed(CompanySettingSeeder::class);

    $setting = CompanySetting::query()->sole();

    expect(CompanySetting::query()->count())->toBe(1)
        ->and($setting->company_name)->toBe('Laravel')
        ->and($setting->working_start_time)->toBe('08:00:00')
        ->and($setting->working_end_time)->toBe('17:00:00')
        ->and($setting->working_days)->toBe([
            'monday',
            'tuesday',
            'wednesday',
            'thursday',
            'friday',
            'saturday',
        ])
        ->and($setting->salary_currency)->toBe('USD')
        ->and($setting->payroll_day_rate)->toBe(26)
        ->and($setting->allowed_radius_meters)->toBe(100);
});

test('company setting service creates defaults when no settings row exists', function () {
    $setting = app(CompanySettingService::class)->current();

    expect($setting->exists)->toBeTrue()
        ->and(CompanySetting::query()->count())->toBe(1)
        ->and($setting->company_name)->toBe('Laravel');
});

test('company setting service enforces a single row when updating settings', function () {
    CompanySetting::query()->create([
        'company_name' => 'Primary Company',
        'working_days' => ['monday', 'tuesday'],
    ]);

    CompanySetting::query()->create([
        'company_name' => 'Duplicate Company',
        'working_days' => ['friday'],
    ]);

    $updated = app(CompanySettingService::class)->update([
        'company_name' => 'Unified Company',
        'company_email' => 'company@example.com',
        'working_days' => ['monday', 'tuesday', 'wednesday'],
    ]);

    expect(CompanySetting::query()->count())->toBe(1)
        ->and($updated->company_name)->toBe('Unified Company')
        ->and($updated->company_email)->toBe('company@example.com')
        ->and($updated->working_days)->toBe(['monday', 'tuesday', 'wednesday']);
});

test('database seeder includes company settings foundation data', function () {
    $this->seed(DatabaseSeeder::class);

    expect(CompanySetting::query()->count())->toBe(1)
        ->and(CompanySetting::query()->sole()->payroll_day_rate)->toBe(26);
});
