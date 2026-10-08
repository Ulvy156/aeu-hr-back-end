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
    $user->givePermissionTo(['announcements.view_draft', 'announcements.create', 'announcements.update', 'announcements.publish', 'announcements.archive']);

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
        'employment_status' => 'full-time',
    ], $employeeOverrides));

    return [$user, $employee, $user->createToken('test-device')->plainTextToken];
}

test('authorized publisher can publish a draft announcement with audit logs', function () {
    $category = announcementCategory();
    [$creator, $creatorToken] = announcementAdmin();

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

    $this->app['auth']->forgetGuards();

    $this->withToken($creatorToken)
        ->postJson("/api/announcements/{$announcementId}/publish")
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'published');

    $this->assertDatabaseHas('announcements', ['id' => $announcementId, 'status' => 'published']);

    expect(
        Activity::query()
            ->where('subject_type', (new Announcement)->getMorphClass())
            ->where('subject_id', $announcementId)
            ->pluck('event')
            ->all()
    )->toEqual(['create', 'publish']);
});

test('a published announcement cannot be published again', function () {
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

    $this->app['auth']->forgetGuards();

    $this->withToken($creatorToken)->postJson("/api/announcements/{$announcementId}/publish");

    $this->app['auth']->forgetGuards();

    $this->withToken($creatorToken)
        ->postJson("/api/announcements/{$announcementId}/publish")
        ->assertForbidden();

    $this->app['auth']->forgetGuards();

    $this->withToken($creatorToken)
        ->postJson("/api/announcements/{$announcementId}/publish")
        ->assertForbidden();
});

test('a draft announcement can be edited and published', function () {
    $category = announcementCategory();
    [$creator, $creatorToken] = announcementAdmin();

    $announcementId = $this->withToken($creatorToken)
        ->postJson('/api/announcements', [
            'category_id' => $category->id,
            'title' => 'Holiday Schedule',
            'content' => 'Initial draft content.',
            'targets' => [['target_type' => 'all']],
        ])
        ->json('data.id');

    $this->app['auth']->forgetGuards();


    $this->withToken($creatorToken)
        ->putJson("/api/announcements/{$announcementId}", [
            'category_id' => $category->id,
            'title' => 'Holiday Schedule',
            'content' => 'Updated content with dates included.',
            'targets' => [['target_type' => 'all']],
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.content', 'Updated content with dates included.')
        ->assertJsonPath('data.status', 'draft');

    $this->app['auth']->forgetGuards();

    $this->withToken($creatorToken)
        ->postJson("/api/announcements/{$announcementId}/publish")
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'published');
});

test('only an authorized publisher can publish a draft', function () {
    $category = announcementCategory();
    [$creator, $creatorToken] = announcementAdmin();
    $other = User::factory()->create(['status' => 'active']);
    $other->assignRole('employee');
    $otherToken = $other->createToken('employee')->plainTextToken;

    $announcementId = $this->withToken($creatorToken)
        ->postJson('/api/announcements', [
            'category_id' => $category->id,
            'title' => 'System Maintenance',
            'content' => 'Scheduled maintenance this weekend.',
            'targets' => [['target_type' => 'all']],
        ])
        ->json('data.id');

    $this->app['auth']->forgetGuards();
    $this->withToken($otherToken)
        ->postJson("/api/announcements/{$announcementId}/publish")
        ->assertForbidden();

    $this->app['auth']->forgetGuards();

    $this->withToken($creatorToken)
        ->postJson("/api/announcements/{$announcementId}/publish")
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'published');
});

test('only published announcements can be archived', function () {
    $category = announcementCategory();
    [$creator, $creatorToken] = announcementAdmin();

    $announcementId = $this->withToken($creatorToken)
        ->postJson('/api/announcements', [
            'category_id' => $category->id,
            'title' => 'New Benefits',
            'content' => 'Details about the new benefits package.',
            'targets' => [['target_type' => 'all']],
        ])
        ->json('data.id');

    $this->app['auth']->forgetGuards();

    $this->withToken($creatorToken)
        ->postJson("/api/announcements/{$announcementId}/archive")
        ->assertForbidden();

    $this->app['auth']->forgetGuards();

    $this->withToken($creatorToken)->postJson("/api/announcements/{$announcementId}/publish");

    $this->app['auth']->forgetGuards();

    $this->withToken($creatorToken)
        ->postJson("/api/announcements/{$announcementId}/archive")
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'archived');

    $this->app['auth']->forgetGuards();

    $this->withToken($creatorToken)
        ->postJson("/api/announcements/{$announcementId}/archive")
        ->assertForbidden();
});

test('announcements require an active category', function () {
    $inactiveCategory = announcementCategory(['name' => 'Retired', 'status' => 'inactive']);
    [, $creatorToken] = announcementAdmin();

    $this->app['auth']->forgetGuards();

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

    $this->app['auth']->forgetGuards();

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
    [$creator, $creatorToken] = announcementAdmin();

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
        role: 'employee',
        permissions: ['announcements.view'],
        employeeOverrides: ['department_id' => $departmentB->id],
    );
    [$specificUser, $specificEmployee, $specificToken] = announcementEmployee(
        permissions: ['announcements.view'],
        employeeOverrides: ['department_id' => $departmentB->id],
    );

    $audienceRole = Role::query()->create(['name' => 'announcement-audience']);
    $hrUser->assignRole($audienceRole);
    $hrRoleId = $audienceRole->id;

    $publish = function (string $token, array $payload) use ($creatorToken) {
        $id = $this->withToken($creatorToken)
            ->postJson('/api/announcements', $payload)
            ->json('data.id');

        $this->app['auth']->forgetGuards();

        $this->withToken($creatorToken)->postJson("/api/announcements/{$id}/publish");

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

    // Exercise the audience query directly so each employee is evaluated as its own actor.
    $service = app(\App\Services\AnnouncementService::class);
    $visibleIds = fn (User $user, Employee $employee) => $service
        ->paginateForEmployee([], $employee, $user)
        ->getCollection()
        ->pluck('id')
        ->all();

    $everyoneIds = $visibleIds($everyoneUser, $everyoneEmployee);
    expect($everyoneIds)->toContain($allId);
    expect(in_array($departmentAnnouncementId, $everyoneIds, true))->toBeFalse();
    expect(in_array($roleAnnouncementId, $everyoneIds, true))->toBeFalse();
    expect(in_array($employeeAnnouncementId, $everyoneIds, true))->toBeFalse();

    $deptIds = $visibleIds($deptUser, $deptEmployee);
    expect($deptIds)->toContain($allId, $departmentAnnouncementId);
    expect(in_array($roleAnnouncementId, $deptIds, true))->toBeFalse();
    expect(in_array($employeeAnnouncementId, $deptIds, true))->toBeFalse();

    $hrIds = $visibleIds($hrUser, $hrEmployee);
    expect($hrIds)->toContain($allId, $roleAnnouncementId);
    expect(in_array($departmentAnnouncementId, $hrIds, true))->toBeFalse();
    expect(in_array($employeeAnnouncementId, $hrIds, true))->toBeFalse();

    $specificIds = $visibleIds($specificUser, $specificEmployee);
    expect($specificIds)->toContain($allId, $employeeAnnouncementId);
    expect(in_array($departmentAnnouncementId, $specificIds, true))->toBeFalse();
    expect(in_array($roleAnnouncementId, $specificIds, true))->toBeFalse();

    $draft = Announcement::query()->create([
        'category_id' => $category->id,
        'title' => 'Still Drafting',
        'content' => 'Not ready yet.',
        'status' => 'draft',
        'created_by' => $creator->id,
    ]);
    $draft->targets()->create(['target_type' => 'all']);

    expect($everyoneIds)->not->toContain($draft->id);
    expect(app(\App\Policies\AnnouncementPolicy::class)->view($everyoneUser, $draft))->toBeFalse();
});

test('viewing an announcement marks it as read and the management read summary reflects audience state', function () {
    $category = announcementCategory();
    [, $creatorToken] = announcementAdmin();

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

    $this->app['auth']->forgetGuards();

    $this->withToken($creatorToken)->postJson("/api/announcements/{$announcementId}/publish");

    // Initially unread for the reader.
    $this->app['auth']->forgetGuards();
    $this->withToken($readerToken)
        ->getJson('/api/announcements')
        ->assertJsonPath('data.0.is_read', false);

    // Viewing the announcement marks it as read.
    $this->app['auth']->forgetGuards();
    $this->withToken($readerToken)
        ->getJson("/api/announcements/{$announcementId}")
        ->assertSuccessful()
        ->assertJsonPath('data.is_read', true);

    $this->assertDatabaseCount('announcement_views', 1);

    // Calling the dedicated read endpoint again is idempotent.
    $this->app['auth']->forgetGuards();
    $this->withToken($readerToken)
        ->postJson("/api/announcements/{$announcementId}/read")
        ->assertSuccessful();

    $this->assertDatabaseCount('announcement_views', 1);

    // The reader now sees the announcement marked as read.
    $this->app['auth']->forgetGuards();
    $this->withToken($readerToken)
        ->getJson('/api/announcements?read_status=read')
        ->assertJsonCount(1, 'data');

    $this->app['auth']->forgetGuards();
    $this->withToken($readerToken)
        ->getJson('/api/announcements?read_status=unread')
        ->assertJsonCount(0, 'data');

    // The other employee has not viewed it yet.
    $this->app['auth']->forgetGuards();
    $this->withToken($otherToken)
        ->getJson('/api/announcements?read_status=unread')
        ->assertJsonCount(1, 'data');

    // Management view exposes a read summary across the audience.
    $this->app['auth']->forgetGuards();
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

    $this->app['auth']->forgetGuards();
    $this->withToken($employeeToken)
        ->postJson('/api/announcements', [
            'category_id' => $category->id,
            'title' => 'Unauthorized',
            'content' => 'Should not be allowed.',
            'targets' => [['target_type' => 'all']],
        ])
        ->assertForbidden();

    $draft = Announcement::query()->create([
        'category_id' => $category->id,
        'title' => 'Draft Only',
        'content' => 'Not yet published.',
        'status' => 'draft',
        'created_by' => $employeeUser->id,
    ]);

    expect(app(\App\Policies\AnnouncementPolicy::class)->view($employeeUser, $draft))->toBeFalse();
});
