<?php

use App\Models\PublicHoliday;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('hr can list create update and disable public holidays', function () {
    PublicHoliday::query()->create([
        'holiday_date' => '2026-04-14',
        'name' => 'Khmer New Year',
        'description' => 'National holiday',
        'status' => 'active',
    ]);

    PublicHoliday::query()->create([
        'holiday_date' => '2025-12-25',
        'name' => 'Archived Holiday',
        'description' => 'Previous year holiday',
        'status' => 'inactive',
    ]);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/public-holidays?status=active&year=2026&search=Khmer&per_page=10')
        ->assertSuccessful()
        ->assertJsonPath('message', 'Public holidays fetched successfully.')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.name', 'Khmer New Year');

    $createResponse = $this->withToken($token)->postJson('/api/public-holidays', [
        'holiday_date' => '2026-11-09',
        'name' => 'Independence Day',
        'description' => 'Public holiday for independence day',
        'status' => 'active',
    ]);

    $createResponse
        ->assertCreated()
        ->assertJsonPath('message', 'Public holiday created successfully.')
        ->assertJsonPath('data.name', 'Independence Day')
        ->assertJsonPath('data.status', 'active');

    $holidayId = $createResponse->json('data.id');

    $this->withToken($token)
        ->putJson("/api/public-holidays/{$holidayId}", [
            'holiday_date' => '2026-11-10',
            'name' => 'Independence Day Observed',
            'description' => 'Observed holiday',
            'status' => 'active',
        ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Public holiday updated successfully.')
        ->assertJsonPath('data.holiday_date', '2026-11-10')
        ->assertJsonPath('data.name', 'Independence Day Observed');

    $this->withToken($token)
        ->deleteJson("/api/public-holidays/{$holidayId}")
        ->assertSuccessful()
        ->assertJsonPath('message', 'Public holiday disabled successfully.')
        ->assertJsonPath('data.status', 'inactive');

    $publicHoliday = PublicHoliday::query()->findOrFail($holidayId);
    $activities = Activity::query()
        ->where('log_name', 'public_holidays')
        ->orderBy('id')
        ->get();

    expect($publicHoliday->status)->toBe('inactive')
        ->and($activities)->toHaveCount(3)
        ->and($activities[0]->description)->toBe('create')
        ->and($activities[1]->description)->toBe('update')
        ->and($activities[2]->description)->toBe('delete')
        ->and($activities[2]->properties->get('old_values')['status'])->toBe('active')
        ->and($activities[2]->properties->get('new_values')['status'])->toBe('inactive');
});

test('admin can manage public holidays', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/public-holidays', [
            'holiday_date' => '2026-01-07',
            'name' => 'Victory Day',
            'description' => 'National public holiday',
            'status' => 'active',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Victory Day');
});

test('public holiday date must remain unique on create and update', function () {
    PublicHoliday::query()->create([
        'holiday_date' => '2026-05-01',
        'name' => 'Labour Day',
        'description' => 'Existing holiday',
        'status' => 'active',
    ]);

    $secondHoliday = PublicHoliday::query()->create([
        'holiday_date' => '2026-06-18',
        'name' => 'Second Holiday',
        'description' => 'Another holiday',
        'status' => 'active',
    ]);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/public-holidays', [
            'holiday_date' => '2026-05-01',
            'name' => 'Duplicate Labour Day',
            'description' => 'Duplicate date',
            'status' => 'active',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors('holiday_date');

    $this->withToken($token)
        ->putJson("/api/public-holidays/{$secondHoliday->id}", [
            'holiday_date' => '2026-05-01',
            'name' => 'Second Holiday Updated',
            'description' => 'Trying duplicate date',
            'status' => 'active',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors('holiday_date');
});

test('employees and ceo cannot manage public holidays', function () {
    $employee = User::factory()->create();
    $employee->assignRole('employee');
    $employeeToken = $employee->createToken('employee-device')->plainTextToken;

    $this->withToken($employeeToken)
        ->getJson('/api/public-holidays')
        ->assertForbidden();

    $ceo = User::factory()->create();
    $ceo->assignRole('ceo');
    $ceoToken = $ceo->createToken('ceo-device')->plainTextToken;

    $this->withToken($ceoToken)
        ->postJson('/api/public-holidays', [
            'holiday_date' => '2026-03-08',
            'name' => 'Blocked Holiday',
            'description' => 'CEO cannot manage',
            'status' => 'active',
        ])
        ->assertForbidden();
});
