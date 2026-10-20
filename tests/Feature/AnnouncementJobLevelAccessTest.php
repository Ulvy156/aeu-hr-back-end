<?php

use App\Enums\JobLevel;
use App\Models\AnnouncementCategory;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Services\UserPermissionService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function announcementLeader(JobLevel $level): array
{
    $department = Department::query()->create(['name' => 'Operations', 'status' => 'active']);
    $position = Position::query()->create([
        'name' => 'Leadership position', 'department_id' => $department->id,
        'job_level' => $level, 'status' => 'active',
    ]);
    $user = User::factory()->create(['status' => 'active'])->assignRole('employee');
    $employee = Employee::query()->create([
        'user_id' => $user->id, 'employee_id' => 'LEADER-001', 'full_name' => 'Announcement Leader',
        'position_id' => $position->id, 'department_id' => $department->id,
        'join_date' => '2026-01-01', 'employment_status' => 'full-time', 'base_salary' => 1000,
    ]);

    return [$user, $employee, $position];
}

function leadershipAnnouncementPayload(): array
{
    $category = AnnouncementCategory::query()->create(['name' => 'General', 'status' => 'active']);

    return ['category_id' => $category->id, 'title' => 'Team update', 'content' => 'Please read this update.',
        'priority' => 'normal', 'targets' => [['target_type' => 'all', 'target_id' => null]]];
}

test('leadership job levels can manage announcements without extra HR or payroll access', function (JobLevel $level) {
    [$user] = announcementLeader($level);
    Sanctum::actingAs($user);
    $payload = leadershipAnnouncementPayload();
    $id = $this->postJson('/api/announcements', $payload)->assertCreated()->json('data.id');
    $this->getJson("/api/announcements/{$id}")->assertSuccessful()->assertJsonPath('data.status', 'draft');
    $payload['title'] = 'Updated team notice';
    $this->putJson("/api/announcements/{$id}", $payload)->assertSuccessful();
    $this->postJson("/api/announcements/{$id}/publish")->assertSuccessful()->assertJsonPath('data.status', 'published');
    $this->postJson("/api/announcements/{$id}/archive")->assertSuccessful()->assertJsonPath('data.status', 'archived');
    $permissions = $this->getJson('/api/me')->assertSuccessful()->json('data.permissions');
    expect($permissions)->toContain('announcements.create', 'announcements.update', 'announcements.publish', 'announcements.archive');
    expect($user->getRoleNames()->all())->toBe(['employee'])
        ->and($user->hasDirectPermission('announcements.create'))->toBeFalse()
        ->and($user->hasPermissionTo('payrolls.generate'))->toBeFalse()
        ->and($user->hasPermissionTo('payrolls.approve'))->toBeFalse()
        ->and($user->hasPermissionTo('leaves.approve_hr'))->toBeFalse()
        ->and($user->hasPermissionTo('announcement_categories.create'))->toBeFalse();
    $this->getJson('/api/employees')->assertForbidden();
    $this->getJson('/api/payrolls')->assertForbidden();
})->with([JobLevel::Head, JobLevel::Ceo, JobLevel::Gm, JobLevel::Manager]);

test('other job levels cannot manage announcements', function (JobLevel $level) {
    [$user] = announcementLeader($level);
    Sanctum::actingAs($user);
    $this->postJson('/api/announcements', leadershipAnnouncementPayload())->assertForbidden();
    $this->getJson('/api/announcements/target-options')->assertForbidden();
    expect($user->hasPermissionTo('announcements.publish'))->toBeFalse()
        ->and(app(UserPermissionService::class)->getAllPermissionNames($user))->not->toContain('announcements.create');
})->with([JobLevel::Junior, JobLevel::Senior, JobLevel::Supervisor]);

test('changing positions or their job level removes automatic grants and preserves manual grants', function () {
    [$user, $employee, $position] = announcementLeader(JobLevel::Head);
    $user->givePermissionTo(['announcements.create', 'employees.search']);
    $user->load('employee.position');
    expect($user->hasPermissionTo('announcements.archive'))->toBeTrue();
    $position->update(['job_level' => JobLevel::Junior]);
    expect($user->hasPermissionTo('announcements.archive'))->toBeFalse()
        ->and($user->hasPermissionTo('announcements.create'))->toBeTrue()
        ->and($user->hasPermissionTo('employees.search'))->toBeTrue()
        ->and($user->getAllPermissions()->pluck('name')->all())->not->toContain('announcements.archive');
    $position->update(['job_level' => JobLevel::Manager]);
    expect($user->hasPermissionTo('announcements.archive'))->toBeTrue();
    $employee->update(['position_id' => null]);
    expect($user->hasPermissionTo('announcements.archive'))->toBeFalse()
        ->and($user->hasPermissionTo('announcements.create'))->toBeTrue();
});

test('CEO announcement management follows the position rather than the retained CEO role', function () {
    [$user, $employee] = announcementLeader(JobLevel::Ceo);
    $user->syncRoles(['ceo']);
    Sanctum::actingAs($user);
    $payload = leadershipAnnouncementPayload();
    $id = $this->postJson('/api/announcements', $payload)->assertCreated()->json('data.id');
    $employee->update(['position_id' => null]);
    $this->postJson("/api/announcements/{$id}/publish")->assertForbidden();
    $this->postJson('/api/announcements', $payload)->assertForbidden();
    expect($user->hasRole('ceo'))->toBeTrue();
    $user->givePermissionTo('announcements.publish');
    $this->postJson("/api/announcements/{$id}/publish")->assertSuccessful();
});

test('announcement audience choices expose names and IDs without granting employee access', function () {
    [$user, $employee] = announcementLeader(JobLevel::Manager);
    Sanctum::actingAs($user);
    $response = $this->getJson('/api/announcements/target-options?q=Leader')->assertSuccessful();
    expect($response->json('data.employees.0'))->toHaveKeys(['id', 'employee_id', 'full_name'])
        ->toHaveCount(3)
        ->and($response->json('data.employees.0.id'))->toBe($employee->id);
    $this->getJson('/api/announcements/target-options?employee_ids[]='.$employee->id)->assertSuccessful()
        ->assertJsonPath('data.employees.0.id', $employee->id);
    $this->getJson('/api/announcements/target-options')->assertSuccessful()->assertJsonCount(0, 'data.employees');
    $this->getJson('/api/announcements/target-options?q=A')->assertUnprocessable();
    $this->getJson('/api/employees/'.$employee->id)->assertForbidden();
});

test('deleting a leadership position removes automatic access while preserving a separate admin grant', function () {
    [$user, , $position] = announcementLeader(JobLevel::Manager);
    $user->givePermissionTo('announcements.create');
    expect($user->hasPermissionTo('announcements.archive'))->toBeTrue();
    $position->delete();
    expect($user->hasPermissionTo('announcements.archive'))->toBeFalse()
        ->and($user->hasPermissionTo('announcements.create'))->toBeTrue();
    $user->revokePermissionTo('announcements.create');
    expect($user->hasPermissionTo('announcements.create'))->toBeFalse();
});
