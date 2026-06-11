<?php

use App\Models\Announcement;
use App\Models\AnnouncementCategory;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(config('filesystems.cloud'));
    $this->seed(RoleSeeder::class);
});

function announcementCategory(array $overrides = []): AnnouncementCategory
{
    return AnnouncementCategory::query()->create(array_merge([
        'name' => 'General',
        'status' => 'active',
    ], $overrides));
}

/**
 * @return array{0: User, 1: string}
 */
function announcementAdmin(): array
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');

    return [$user, $user->createToken('admin-device')->plainTextToken];
}

/**
 * @param  array<int, string>  $permissions
 * @param  array<string, mixed>  $employeeOverrides
 * @return array{0: User, 1: Employee, 2: string}
 */
function announcementEmployee(string $role = 'employee', array $permissions = [], array $employeeOverrides = []): array
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    if (! empty($permissions)) {
        $user->givePermissionTo($permissions);
    }

    $employee = Employee::query()->create(array_merge([
        'user_id' => $user->id,
        'employee_id' => 'EMP-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
        'full_name' => $user->name,
        'join_date' => '2026-01-01',
        'base_salary' => 1000,
        'employment_status' => 'active',
    ], $employeeOverrides));

    return [$user, $employee, $user->createToken('test-device')->plainTextToken];
}

test('admin workflow: announcement moves from draft to pending approval to published with audit logs', function () {
    $category = announcementCategory();
    [$creator, $creatorToken] = announcementAdmin();
    [$approver, $approverToken] = announcementAdmin();

    $createResponse = $this->withToken($creatorToken)
        ->postJson('/api/announcements', [
            'category_id' => $category->id,
            'title' => 'Office Closure',
            'content' => 'The office will be closed on Friday.',
            'priority' => 'important',
            'targets' => [['target_type' => 'all']],
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.title', 'Office Closure')
        ->assertJsonPath('data.creator.id', $creator->id);

    $announcementId = $createResponse->json('data.id');

    $this->withToken($creatorToken)
        ->postJson("/api/announcements/{$announcementId}/submit")
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'pending_approval');

    $this->withToken($approverToken)
        ->postJson("/api/announcements/{$announcementId}/approve")
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'published')
        ->assertJsonPath('data.approved_by_user.id', $approver->id);

    expect(
        Activity::query()
            ->where('subject_type', (new Announcement)->getMorphClass())
            ->where('subject_id', $announcementId)
            ->pluck('event')
            ->all()
    )->toEqual(['create', 'submit', 'approve']);
});

test('creator cannot approve or reject their own announcement', function () {
    $category = announcementCategory();
    [$creator, $creatorToken] = announcementAdmin();

    $announcementId = $this->withToken($creatorToken)
        ->postJson('/api/announcements', [
            'category_id' => $category->id,
            'title' => 'Policy Update',
            'content' => 'Please review the updated policy.',
            'targets' => [['target_type' => 'all']],
        ])
        ->json('data.id');

    $this->withToken($creatorToken)->postJson("/api/announcements/{$announcementId}/submit");

    $this->withToken($creatorToken)
        ->postJson("/api/announcements/{$announcementId}/approve")
        ->assertForbidden();

    $this->withToken($creatorToken)
        ->postJson("/api/announcements/{$announcementId}/reject", ['rejection_reason' => 'Needs more detail'])
        ->assertForbidden();
});

test('rejected announcements can be edited and resubmitted', function () {
    $category = announcementCategory();
    [$creator, $creatorToken] = announcementAdmin();
    [, $approverToken] = announcementAdmin();

    $announcementId = $this->withToken($creatorToken)
        ->postJson('/api/announcements', [
            'category_id' => $category->id,
            'title' => 'Holiday Schedule',
            'content' => 'Initial draft content.',
            'targets' => [['target_type' => 'all']],
        ])
        ->json('data.id');

    $this->withToken($creatorToken)->postJson("/api/announcements/{$announcementId}/submit");

    $this->withToken($approverToken)
        ->postJson("/api/announcements/{$announcementId}/reject", ['rejection_reason' => 'Please add the dates'])
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.rejection_reason', 'Please add the dates');

    $this->withToken($creatorToken)
        ->putJson("/api/announcements/{$announcementId}", [
            'category_id' => $category->id,
            'title' => 'Holiday Schedule',
            'content' => 'Updated content with dates included.',
            'targets' => [['target_type' => 'all']],
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.content', 'Updated content with dates included.')
        ->assertJsonPath('data.rejection_reason', null);

    $this->withToken($creatorToken)
        ->postJson("/api/announcements/{$announcementId}/submit")
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'pending_approval');
});

test('only the creator can cancel a pending submission', function () {
    $category = announcementCategory();
    [$creator, $creatorToken] = announcementAdmin();
    [, $otherToken] = announcementAdmin();

    $announcementId = $this->withToken($creatorToken)
        ->postJson('/api/announcements', [
            'category_id' => $category->id,
            'title' => 'System Maintenance',
            'content' => 'Scheduled maintenance this weekend.',
            'targets' => [['target_type' => 'all']],
        ])
        ->json('data.id');

    $this->withToken($creatorToken)->postJson("/api/announcements/{$announcementId}/submit");

    $this->withToken($otherToken)
        ->postJson("/api/announcements/{$announcementId}/cancel-submission")
        ->assertForbidden();

    $this->withToken($creatorToken)
        ->postJson("/api/announcements/{$announcementId}/cancel-submission")
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'draft');
});

test('only published announcements can be archived', function () {
    $category = announcementCategory();
    [$creator, $creatorToken] = announcementAdmin();
    [, $approverToken] = announcementAdmin();

    $announcementId = $this->withToken($creatorToken)
        ->postJson('/api/announcements', [
            'category_id' => $category->id,
            'title' => 'New Benefits',
            'content' => 'Details about the new benefits package.',
            'targets' => [['target_type' => 'all']],
        ])
        ->json('data.id');

    $this->withToken($creatorToken)
        ->postJson("/api/announcements/{$announcementId}/archive")
        ->assertForbidden();

    $this->withToken($creatorToken)->postJson("/api/announcements/{$announcementId}/submit");
    $this->withToken($approverToken)->postJson("/api/announcements/{$announcementId}/approve");

    $this->withToken($creatorToken)
        ->postJson("/api/announcements/{$announcementId}/archive")
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'archived');

    $this->withToken($creatorToken)
        ->postJson("/api/announcements/{$announcementId}/archive")
        ->assertForbidden();
});

test('announcements require an active category', function () {
    $inactiveCategory = announcementCategory(['name' => 'Retired', 'status' => 'inactive']);
    [, $creatorToken] = announcementAdmin();

    $this->withToken($creatorToken)
        ->postJson('/api/announcements', [
            'category_id' => $inactiveCategory->id,
            'title' => 'Should Fail',
            'content' => 'This should not be created.',
            'targets' => [['target_type' => 'all']],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('category_id');
});

test('attachment upload validates mime type and can be removed on update', function () {
    $category = announcementCategory();
    [, $creatorToken] = announcementAdmin();

    $invalid = $this->withToken($creatorToken)
        ->post('/api/announcements', [
            'category_id' => $category->id,
            'title' => 'With Attachment',
            'content' => 'See attached file.',
            'targets' => [['target_type' => 'all']],
            'attachment' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ], ['Accept' => 'application/json']);

    $invalid->assertUnprocessable()->assertJsonValidationErrors('attachment');

    $createResponse = $this->withToken($creatorToken)
        ->post('/api/announcements', [
            'category_id' => $category->id,
            'title' => 'With Attachment',
            'content' => 'See attached file.',
            'targets' => [['target_type' => 'all']],
            'attachment' => UploadedFile::fake()->create('memo.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json']);

    $createResponse->assertCreated()->assertJsonPath('data.attachment.name', 'memo.pdf');

    $announcement = Announcement::query()->findOrFail($createResponse->json('data.id'));
    Storage::disk(config('filesystems.cloud'))->assertExists($announcement->attachment_path);

    $this->withToken($creatorToken)
        ->putJson("/api/announcements/{$announcement->id}", [
            'category_id' => $category->id,
            'title' => 'With Attachment',
            'content' => 'See attached file.',
            'targets' => [['target_type' => 'all']],
            'remove_attachment' => true,
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.attachment', null);

    Storage::disk(config('filesystems.cloud'))->assertMissing($announcement->attachment_path);
});

test('employees only see published announcements that target them', function () {
    $category = announcementCategory();
    [, $creatorToken] = announcementAdmin();
    [, $approverToken] = announcementAdmin();

    $departmentA = Department::query()->create(['name' => 'Engineering', 'status' => 'active']);
    $departmentB = Department::query()->create(['name' => 'Sales', 'status' => 'active']);

    [$everyoneUser, $everyoneEmployee, $everyoneToken] = announcementEmployee(
        permissions: ['announcements.view'],
        employeeOverrides: ['department_id' => $departmentB->id],
    );
    [$deptUser, $deptEmployee, $deptToken] = announcementEmployee(
        permissions: ['announcements.view'],
        employeeOverrides: ['department_id' => $departmentA->id],
    );
    [$hrUser, $hrEmployee, $hrToken] = announcementEmployee(
        role: 'hr',
        permissions: ['announcements.view'],
        employeeOverrides: ['department_id' => $departmentB->id],
    );
    [$specificUser, $specificEmployee, $specificToken] = announcementEmployee(
        permissions: ['announcements.view'],
        employeeOverrides: ['department_id' => $departmentB->id],
    );

    $hrRoleId = Role::where('name', 'hr')->sole()->id;

    $publish = function (string $token, array $payload) use ($creatorToken, $approverToken) {
        $id = $this->withToken($creatorToken)
            ->postJson('/api/announcements', $payload)
            ->json('data.id');

        $this->withToken($creatorToken)->postJson("/api/announcements/{$id}/submit");
        $this->withToken($approverToken)->postJson("/api/announcements/{$id}/approve");

        return $id;
    };

    $allId = $publish($creatorToken, [
        'category_id' => $category->id,
        'title' => 'Company Wide',
        'content' => 'For everyone.',
        'targets' => [['target_type' => 'all']],
    ]);

    $departmentAnnouncementId = $publish($creatorToken, [
        'category_id' => $category->id,
        'title' => 'Engineering Only',
        'content' => 'For the engineering department.',
        'targets' => [['target_type' => 'department', 'target_id' => $departmentA->id]],
    ]);

    $roleAnnouncementId = $publish($creatorToken, [
        'category_id' => $category->id,
        'title' => 'HR Only',
        'content' => 'For HR staff.',
        'targets' => [['target_type' => 'role', 'target_id' => $hrRoleId]],
    ]);

    $employeeAnnouncementId = $publish($creatorToken, [
        'category_id' => $category->id,
        'title' => 'Just For You',
        'content' => 'For one specific employee.',
        'targets' => [['target_type' => 'employee', 'target_id' => $specificEmployee->id]],
    ]);

    // The "all" targeted announcement is visible to every employee.
    $everyoneIds = $this->withToken($everyoneToken)->getJson('/api/announcements')->json('data.*.id');
    expect($everyoneIds)->toContain($allId);
    expect($everyoneIds)->not->toContain($departmentAnnouncementId, $roleAnnouncementId, $employeeAnnouncementId);

    // Department-targeted announcement is only visible to employees in that department.
    $deptIds = $this->withToken($deptToken)->getJson('/api/announcements')->json('data.*.id');
    expect($deptIds)->toContain($allId, $departmentAnnouncementId);
    expect($deptIds)->not->toContain($roleAnnouncementId, $employeeAnnouncementId);

    // Role-targeted announcement is only visible to employees holding that role.
    $hrIds = $this->withToken($hrToken)->getJson('/api/announcements')->json('data.*.id');
    expect($hrIds)->toContain($allId, $roleAnnouncementId);
    expect($hrIds)->not->toContain($departmentAnnouncementId, $employeeAnnouncementId);

    // Employee-targeted announcement is only visible to that specific employee.
    $specificIds = $this->withToken($specificToken)->getJson('/api/announcements')->json('data.*.id');
    expect($specificIds)->toContain($allId, $employeeAnnouncementId);
    expect($specificIds)->not->toContain($departmentAnnouncementId, $roleAnnouncementId);

    // Viewing an announcement that is not targeted to the employee is forbidden.
    $this->withToken($everyoneToken)
        ->getJson("/api/announcements/{$departmentAnnouncementId}")
        ->assertForbidden();

    // Employees without the view_draft permission cannot see drafts/pending announcements.
    $draftId = $this->withToken($creatorToken)
        ->postJson('/api/announcements', [
            'category_id' => $category->id,
            'title' => 'Still Drafting',
            'content' => 'Not ready yet.',
            'targets' => [['target_type' => 'all']],
        ])
        ->json('data.id');

    expect($everyoneIds)->not->toContain($draftId);
    $this->withToken($everyoneToken)->getJson("/api/announcements/{$draftId}")->assertForbidden();
});

test('viewing an announcement marks it as read and the management read summary reflects audience state', function () {
    $category = announcementCategory();
    [, $creatorToken] = announcementAdmin();
    [, $approverToken] = announcementAdmin();

    [$readerUser, $readerEmployee, $readerToken] = announcementEmployee(permissions: ['announcements.view']);
    [$otherUser, $otherEmployee, $otherToken] = announcementEmployee(permissions: ['announcements.view']);

    $announcementId = $this->withToken($creatorToken)
        ->postJson('/api/announcements', [
            'category_id' => $category->id,
            'title' => 'Read Tracking',
            'content' => 'Please read this announcement.',
            'targets' => [['target_type' => 'all']],
        ])
        ->json('data.id');

    $this->withToken($creatorToken)->postJson("/api/announcements/{$announcementId}/submit");
    $this->withToken($approverToken)->postJson("/api/announcements/{$announcementId}/approve");

    // Initially unread for the reader.
    $this->withToken($readerToken)
        ->getJson('/api/announcements')
        ->assertJsonPath('data.0.is_read', false);

    // Viewing the announcement marks it as read.
    $this->withToken($readerToken)
        ->getJson("/api/announcements/{$announcementId}")
        ->assertSuccessful()
        ->assertJsonPath('data.is_read', true);

    $this->assertDatabaseCount('announcement_views', 1);

    // Calling the dedicated read endpoint again is idempotent.
    $this->withToken($readerToken)
        ->postJson("/api/announcements/{$announcementId}/read")
        ->assertSuccessful();

    $this->assertDatabaseCount('announcement_views', 1);

    // The reader now sees the announcement marked as read.
    $this->withToken($readerToken)
        ->getJson('/api/announcements?read_status=read')
        ->assertJsonCount(1, 'data');

    $this->withToken($readerToken)
        ->getJson('/api/announcements?read_status=unread')
        ->assertJsonCount(0, 'data');

    // The other employee has not viewed it yet.
    $this->withToken($otherToken)
        ->getJson('/api/announcements?read_status=unread')
        ->assertJsonCount(1, 'data');

    // Management view exposes a read summary across the audience.
    $this->withToken($creatorToken)
        ->getJson("/api/announcements/{$announcementId}")
        ->assertSuccessful()
        ->assertJsonPath('data.read_summary.total_viewed', 1)
        ->assertJsonPath('data.read_summary.total_unread', 1)
        ->assertJsonPath('data.read_summary.viewed_employees.0.id', $readerEmployee->id)
        ->assertJsonPath('data.read_summary.unread_employees.0.id', $otherEmployee->id);
});

test('employees cannot create announcements or view other drafts', function () {
    $category = announcementCategory();
    [$employeeUser, $employee, $employeeToken] = announcementEmployee(permissions: ['announcements.view']);

    $this->withToken($employeeToken)
        ->postJson('/api/announcements', [
            'category_id' => $category->id,
            'title' => 'Unauthorized',
            'content' => 'Should not be allowed.',
            'targets' => [['target_type' => 'all']],
        ])
        ->assertForbidden();

    [, $creatorToken] = announcementAdmin();

    $draftId = $this->withToken($creatorToken)
        ->postJson('/api/announcements', [
            'category_id' => $category->id,
            'title' => 'Draft Only',
            'content' => 'Not yet published.',
            'targets' => [['target_type' => 'all']],
        ])
        ->json('data.id');

    $this->withToken($employeeToken)
        ->getJson("/api/announcements/{$draftId}")
        ->assertForbidden();
});
