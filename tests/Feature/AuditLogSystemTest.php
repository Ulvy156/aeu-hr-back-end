<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('login and logout actions are written to the audit log', function () {
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

    expect(AuditLog::query()->count())->toBe(1)
        ->and(AuditLog::query()->first()->action)->toBe('login')
        ->and(AuditLog::query()->first()->module)->toBe('auth');

    $this->withToken($plainTextToken)
        ->postJson('/api/logout')
        ->assertSuccessful();

    $auditLogs = AuditLog::query()
        ->where('module', 'auth')
        ->orderBy('id')
        ->get();

    expect($auditLogs)->toHaveCount(2)
        ->and($auditLogs[0]->action)->toBe('login')
        ->and($auditLogs[1]->action)->toBe('logout');
});

test('admin can view paginated audit logs', function () {
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
