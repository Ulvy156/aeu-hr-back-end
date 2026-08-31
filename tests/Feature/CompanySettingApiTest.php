<?php

use App\Models\CompanySetting;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(config('filesystems.cloud'));
    $this->seed(RoleSeeder::class);
});

test('hr can view the singleton company settings and defaults are created on demand', function () {
    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/settings/company')
        ->assertSuccessful()
        ->assertJsonPath('message', 'Company settings fetched successfully.')
        ->assertJsonPath('data.company_name', config('hr.company_settings.company_name'))
        ->assertJsonPath('data.working_start_time', '08:00:00')
        ->assertJsonPath('data.working_days.0', 'monday');

    expect(CompanySetting::query()->count())->toBe(1);
});

test('admin can update company settings with a logo and the update is audited', function () {
    Storage::disk(config('filesystems.cloud'))->put('company-logos/old-logo.png', 'old-logo-content');

    CompanySetting::query()->create([
        'company_name' => 'Old Company',
        'company_logo' => 'company-logos/old-logo.png',
        'working_days' => ['monday', 'tuesday'],
    ]);

    CompanySetting::query()->create([
        'company_name' => 'Duplicate Company',
        'working_days' => ['friday'],
    ]);

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $response = $this->withToken($token)->put('/api/settings/company', [
        'company_name' => 'AEU HR',
        'company_logo' => UploadedFile::fake()->image('logo.png'),
        'company_address' => 'Phnom Penh, Cambodia',
        'company_phone' => '+85512345678',
        'company_email' => 'hr@aeu.test',
        'office_latitude' => '11.5564',
        'office_longitude' => '104.9282',
        'allowed_radius_meters' => 250,
        'working_start_time' => '09:00',
        'working_end_time' => '18:00',
        'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
        'salary_currency' => 'khr',
        'payroll_day_rate' => 24,
    ], [
        'Accept' => 'application/json',
    ]);

    $response
        ->assertSuccessful()
        ->assertJsonPath('message', 'Company settings updated successfully.')
        ->assertJsonPath('data.company_name', 'AEU HR')
        ->assertJsonPath('data.company_email', 'hr@aeu.test')
        ->assertJsonPath('data.office_latitude', '11.55640000')
        ->assertJsonPath('data.office_longitude', '104.92820000')
        ->assertJsonPath('data.allowed_radius_meters', 250)
        ->assertJsonPath('data.working_start_time', '09:00:00')
        ->assertJsonPath('data.working_end_time', '18:00:00')
        ->assertJsonPath('data.working_days', ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'])
        ->assertJsonPath('data.salary_currency', 'KHR')
        ->assertJsonPath('data.payroll_day_rate', 24);

    $setting = CompanySetting::query()->sole();
    $activity = Activity::query()
        ->where('log_name', 'company_settings')
        ->where('description', 'update')
        ->latest('id')
        ->first();

    expect(CompanySetting::query()->count())->toBe(1)
        ->and($setting->company_name)->toBe('AEU HR')
        ->and($setting->salary_currency)->toBe('KHR')
        ->and($setting->payroll_day_rate)->toBe(24)
        ->and($setting->working_days)->toBe(['monday', 'tuesday', 'wednesday', 'thursday', 'friday'])
        ->and($setting->company_logo)->toStartWith('company-logos/')
        ->and($activity)->not->toBeNull()
        ->and($activity->properties->get('old_values')['company_name'])->toBe('Old Company')
        ->and($activity->properties->get('new_values')['company_name'])->toBe('AEU HR')
        ->and($activity->properties->get('new_values')['payroll_day_rate'])->toBe(24);

    Storage::disk(config('filesystems.cloud'))->assertMissing('company-logos/old-logo.png');
    Storage::disk(config('filesystems.cloud'))->assertExists($setting->company_logo);
});

test('admin can update company settings via post with multipart form data', function () {
    CompanySetting::query()->create([
        'company_name' => 'Old Company',
        'working_days' => ['monday', 'tuesday'],
    ]);

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $response = $this->withToken($token)->post('/api/settings/company', [
        'company_name' => 'AEU HR via POST',
        'company_logo' => UploadedFile::fake()->image('logo.png'),
        'working_days' => ['monday', 'tuesday', 'wednesday'],
    ], [
        'Accept' => 'application/json',
    ]);

    $response
        ->assertSuccessful()
        ->assertJsonPath('data.company_name', 'AEU HR via POST');

    $setting = CompanySetting::query()->sole();
    expect($setting->company_name)->toBe('AEU HR via POST')
        ->and($setting->company_logo)->toStartWith('company-logos/');

    Storage::disk(config('filesystems.cloud'))->assertExists($setting->company_logo);
});

test('employees cannot view company settings and hr cannot update them', function () {
    $employee = User::factory()->create();
    $employee->assignRole('employee');
    $employeeToken = $employee->createToken('employee-device')->plainTextToken;

    $this->withToken($employeeToken)
        ->getJson('/api/settings/company')
        ->assertForbidden();

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $hrToken = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($hrToken)
        ->putJson('/api/settings/company', [
            'company_name' => 'HR Cannot Update',
        ])
        ->assertForbidden();
});

test('company settings update validates office gps pairs and working hour ranges', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $this->withToken($token)
        ->putJson('/api/settings/company', [
            'office_latitude' => '11.5564',
            'working_start_time' => '18:00',
            'working_end_time' => '09:00',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors(['office_longitude', 'working_end_time']);
});
