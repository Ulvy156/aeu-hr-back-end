<?php

use App\Models\User;
use App\Services\AuditLogService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

test('login and logout actions are written to the spatie activity log', function () {
    $this->seed(RoleSeeder::class);

    $user = User::factory()->create([
        'email' => 'employee@example.com',
        'password' => 'secret-password',
    ]);

    $user->assignRole('employee');

    $loginResponse = $this->postJson('/api/login', [
        'email' => 'employee@example.com',
        'password' => 'secret-password',
        'device_name' => 'web-client',
    ])->assertSuccessful();

    $plainTextToken = $loginResponse->json('data.token');

    $loginActivity = Activity::query()->first();

    $this->assertDatabaseCount('activity_log', 1);

    expect(Activity::query()->count())->toBe(1)
        ->and($loginActivity->log_name)->toBe('auth')
        ->and($loginActivity->description)->toBe('login')
        ->and($loginActivity->causer_id)->toBe($user->id)
        ->and($loginActivity->properties->get('new_values')['device_name'])->toBe('web-client');

    $this->withToken($plainTextToken)
        ->postJson('/api/logout')
        ->assertSuccessful();

    $activities = Activity::query()
        ->where('log_name', 'auth')
        ->orderBy('id')
        ->get();

    $this->assertDatabaseCount('activity_log', 2);

    expect($activities)->toHaveCount(2)
        ->and($activities[0]->description)->toBe('login')
        ->and($activities[1]->description)->toBe('logout')
        ->and($activities[1]->properties->get('old_values'))->toBeNull()
        ->and($activities[1]->properties->toJson())->not->toContain('token');
});

test('admin can view paginated audit logs from the spatie activity table', function () {
    $this->seed(RoleSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $actor = User::factory()->create();

    $auditLogService = app(AuditLogService::class);
    $auditLogService->log('login', 'auth', $actor, $actor, null, ['status' => 'logged_in']);
    $auditLogService->log('update', 'employees', $actor, $actor, ['status' => 'active'], ['status' => 'inactive']);

    $token = $admin->createToken('admin-device')->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/audit-logs?module=auth&per_page=1');

    $response
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Audit logs fetched successfully.')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.module', 'auth')
        ->assertJsonPath('data.0.action', 'login');
});

test('audit log list can be filtered by user module action and date range', function () {
    $this->seed(RoleSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $actor = User::factory()->create();
    $otherActor = User::factory()->create();

    $auditLogService = app(AuditLogService::class);
    $auditLogService->log('login', 'auth', $actor, $actor, null, ['status' => 'logged_in']);
    $auditLogService->log('approve', 'leaves', $otherActor, $otherActor, ['status' => 'pending'], ['status' => 'approved']);

    $token = $admin->createToken('admin-device')->plainTextToken;
    $today = now()->toDateString();

    $this->withToken($token)
        ->getJson("/api/audit-logs?user_id={$actor->id}&module=auth&action=login&date_from={$today}&date_to={$today}")
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.user.id', $actor->id)
        ->assertJsonPath('data.0.module', 'auth')
        ->assertJsonPath('data.0.action', 'login');
});

test('ceo can view audit logs', function () {
    $this->seed(RoleSeeder::class);

    app(AuditLogService::class)->log('login', 'auth', null, User::class, null, ['status' => 'logged_in']);

    $ceo = User::factory()->create();
    $ceo->assignRole('ceo');
    $ceoToken = $ceo->createToken('ceo-device')->plainTextToken;

    $this->withToken($ceoToken)
        ->getJson('/api/audit-logs')
        ->assertSuccessful()
        ->assertJsonPath('success', true);
});

test('hr cannot view audit logs', function () {
    $this->seed(RoleSeeder::class);

    app(AuditLogService::class)->log('login', 'auth', null, User::class, null, ['status' => 'logged_in']);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $hrToken = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($hrToken)
        ->getJson('/api/audit-logs')
        ->assertForbidden();
});

test('employee cannot view audit logs', function () {
    $this->seed(RoleSeeder::class);

    app(AuditLogService::class)->log('login', 'auth', null, User::class, null, ['status' => 'logged_in']);

    $employee = User::factory()->create();
    $employee->assignRole('employee');
    $employeeToken = $employee->createToken('employee-device')->plainTextToken;

    $this->withToken($employeeToken)
        ->getJson('/api/audit-logs')
        ->assertForbidden();
});
