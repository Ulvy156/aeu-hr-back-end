<?php

use App\Models\Department;
use App\Models\RecruitmentVacancy;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function recruitmentDepartment(array $overrides = []): Department
{
    return Department::query()->create(array_merge([
        'name' => 'Engineering '.uniqid(),
        'status' => 'active',
    ], $overrides));
}

/**
 * @return array{0: User, 1: string}
 */
function recruitmentAdmin(): array
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');
    $user->givePermissionTo(['recruitment.vacancies.view', 'recruitment.vacancies.create', 'recruitment.vacancies.update', 'recruitment.vacancies.close']);

    return [$user, $user->createToken('admin-device')->plainTextToken];
}

/**
 * @param  array<int, string>  $permissions
 * @return array{0: User, 1: string}
 */
function recruitmentUser(array $permissions = []): array
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('employee');

    if (! empty($permissions)) {
        $user->givePermissionTo($permissions);
    }

    return [$user, $user->createToken('test-device')->plainTextToken];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function recruitmentVacancyRecord(User $creator, Department $department, array $overrides = []): RecruitmentVacancy
{
    return RecruitmentVacancy::query()->create(array_merge([
        'title' => 'Backend Developer',
        'department_id' => $department->id,
        'description' => 'Build APIs.',
        'required_headcount' => 2,
        'filled_headcount' => 0,
        'open_date' => now()->toDateString(),
        'target_hiring_date' => now()->addMonth()->toDateString(),
        'status' => 'open',
        'created_by' => $creator->id,
    ], $overrides));
}

test('unauthenticated user cannot access vacancies', function () {
    $this->getJson('/api/recruitment/vacancies')->assertUnauthorized();
});

test('admin can create a vacancy with open status when open date is today', function () {
    $department = recruitmentDepartment();
    [$admin, $token] = recruitmentAdmin();
    $openDate = now()->toDateString();

    $this->withToken($token)
        ->postJson('/api/recruitment/vacancies', [
            'title' => 'Backend Developer',
            'department_id' => $department->id,
            'description' => 'Build and maintain APIs.',
            'required_headcount' => 2,
            'open_date' => $openDate,
            'target_hiring_date' => now()->addMonth()->toDateString(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Backend Developer')
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.open_date', $openDate)
        ->assertJsonPath('data.accepts_applications', true)
        ->assertJsonPath('data.filled_headcount', 0)
        ->assertJsonPath('data.required_headcount', 2)
        ->assertJsonPath('data.department.id', $department->id)
        ->assertJsonPath('data.creator.id', $admin->id);

    expect(
        Activity::query()
            ->where('log_name', 'recruitment_vacancies')
            ->pluck('event')
            ->all()
    )->toEqual(['create']);
});

test('admin can create a scheduled vacancy when open date is in the future', function () {
    $department = recruitmentDepartment();
    [, $token] = recruitmentAdmin();
    $openDate = now()->addWeek()->toDateString();

    $this->withToken($token)
        ->postJson('/api/recruitment/vacancies', [
            'title' => 'Future Role',
            'department_id' => $department->id,
            'description' => 'Starts later.',
            'required_headcount' => 1,
            'open_date' => $openDate,
            'target_hiring_date' => now()->addMonths(2)->toDateString(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'scheduled')
        ->assertJsonPath('data.open_date', $openDate)
        ->assertJsonPath('data.accepts_applications', false);
});

test('vacancy creation validates dates and required fields', function () {
    $department = recruitmentDepartment();
    [, $token] = recruitmentAdmin();

    $this->withToken($token)
        ->postJson('/api/recruitment/vacancies', [
            'title' => 'Backend Developer',
            'department_id' => $department->id,
            'description' => 'Build and maintain APIs.',
            'required_headcount' => 0,
            'open_date' => now()->toDateString(),
            'target_hiring_date' => now()->addMonth()->toDateString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('required_headcount');

    $this->withToken($token)
        ->postJson('/api/recruitment/vacancies', [
            'title' => 'Backend Developer',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['department_id', 'description', 'required_headcount', 'open_date', 'target_hiring_date']);

    $this->withToken($token)
        ->postJson('/api/recruitment/vacancies', [
            'title' => 'Backend Developer',
            'department_id' => $department->id,
            'description' => 'Build and maintain APIs.',
            'required_headcount' => 1,
            'open_date' => now()->addWeek()->toDateString(),
            'close_date' => now()->addDays(3)->toDateString(),
            'target_hiring_date' => now()->toDateString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['close_date', 'target_hiring_date']);
});

test('user without recruitment.vacancies.create permission cannot create a vacancy', function () {
    $department = recruitmentDepartment();
    [, $token] = recruitmentUser();

    $this->withToken($token)
        ->postJson('/api/recruitment/vacancies', [
            'title' => 'Backend Developer',
            'department_id' => $department->id,
            'description' => 'Build and maintain APIs.',
            'required_headcount' => 2,
            'open_date' => now()->toDateString(),
            'target_hiring_date' => now()->addMonth()->toDateString(),
        ])
        ->assertForbidden();
});

test('vacancies can be listed, filtered and viewed', function () {
    $engineering = recruitmentDepartment(['name' => 'Engineering']);
    $sales = recruitmentDepartment(['name' => 'Sales']);
    [$admin, $token] = recruitmentAdmin();

    $open = recruitmentVacancyRecord($admin, $engineering, [
        'title' => 'Backend Developer',
        'description' => 'Build APIs.',
    ]);

    recruitmentVacancyRecord($admin, $sales, [
        'title' => 'Sales Executive',
        'description' => 'Drive sales.',
        'required_headcount' => 1,
        'filled_headcount' => 1,
        'status' => 'closed',
    ]);

    recruitmentVacancyRecord($admin, $engineering, [
        'title' => 'Future Hire',
        'open_date' => now()->addWeek()->toDateString(),
        'status' => 'scheduled',
    ]);

    $this->withToken($token)
        ->getJson('/api/recruitment/vacancies?status=open')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Backend Developer')
        ->assertJsonPath('summary.open_count', 1)
        ->assertJsonPath('summary.scheduled_count', 1)
        ->assertJsonPath('summary.closed_count', 1);

    $this->withToken($token)
        ->getJson('/api/recruitment/vacancies?status=scheduled')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Future Hire');

    $this->withToken($token)
        ->getJson("/api/recruitment/vacancies?department={$sales->id}")
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Sales Executive');

    $this->withToken($token)
        ->getJson("/api/recruitment/vacancies/{$open->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.title', 'Backend Developer')
        ->assertJsonPath('data.department.id', $engineering->id);
});

test('vacancy list summary ignores status and counts overdue open roles', function () {
    $engineering = recruitmentDepartment(['name' => 'Engineering']);
    $sales = recruitmentDepartment(['name' => 'Sales']);
    [$admin, $token] = recruitmentAdmin();

    recruitmentVacancyRecord($admin, $engineering, [
        'title' => 'Backend Developer',
        'required_headcount' => 2,
        'filled_headcount' => 1,
        'open_date' => now()->subWeek()->toDateString(),
        'target_hiring_date' => now()->subDays(3)->toDateString(),
    ]);

    recruitmentVacancyRecord($admin, $engineering, [
        'title' => 'QA Engineer',
        'required_headcount' => 3,
        'filled_headcount' => 0,
        'target_hiring_date' => now()->addMonth()->toDateString(),
    ]);

    recruitmentVacancyRecord($admin, $sales, [
        'title' => 'Sales Executive',
        'required_headcount' => 1,
        'filled_headcount' => 1,
        'open_date' => now()->subMonth()->toDateString(),
        'target_hiring_date' => now()->subWeek()->toDateString(),
        'status' => 'closed',
    ]);

    recruitmentVacancyRecord($admin, $engineering, [
        'title' => 'Scheduled Role',
        'open_date' => now()->addWeek()->toDateString(),
        'status' => 'scheduled',
    ]);

    $this->withToken($token)
        ->getJson('/api/recruitment/vacancies?status=closed')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('summary.scheduled_count', 1)
        ->assertJsonPath('summary.open_count', 2)
        ->assertJsonPath('summary.closed_count', 1)
        ->assertJsonPath('summary.open_required_headcount', 5)
        ->assertJsonPath('summary.open_filled_headcount', 1)
        ->assertJsonPath('summary.overdue_open_count', 1);

    $this->withToken($token)
        ->getJson("/api/recruitment/vacancies?department={$sales->id}")
        ->assertSuccessful()
        ->assertJsonPath('summary.scheduled_count', 0)
        ->assertJsonPath('summary.open_count', 0)
        ->assertJsonPath('summary.closed_count', 1)
        ->assertJsonPath('summary.open_required_headcount', 0)
        ->assertJsonPath('summary.open_filled_headcount', 0)
        ->assertJsonPath('summary.overdue_open_count', 0);
});

test('vacancy can be updated while open and status follows open date', function () {
    $department = recruitmentDepartment();
    [$admin, $token] = recruitmentAdmin();

    $vacancy = recruitmentVacancyRecord($admin, $department);

    $this->withToken($token)
        ->putJson("/api/recruitment/vacancies/{$vacancy->id}", [
            'title' => 'Senior Backend Developer',
            'department_id' => $department->id,
            'description' => 'Build and scale APIs.',
            'required_headcount' => 3,
            'open_date' => now()->addWeek()->toDateString(),
            'target_hiring_date' => now()->addMonths(2)->toDateString(),
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.title', 'Senior Backend Developer')
        ->assertJsonPath('data.required_headcount', 3)
        ->assertJsonPath('data.status', 'scheduled');
});

test('updating a closed vacancy does not reopen it', function () {
    $department = recruitmentDepartment();
    [$admin, $token] = recruitmentAdmin();

    $vacancy = recruitmentVacancyRecord($admin, $department, ['status' => 'closed']);

    $this->withToken($token)
        ->putJson("/api/recruitment/vacancies/{$vacancy->id}", [
            'title' => 'Backend Developer',
            'department_id' => $department->id,
            'description' => 'Build APIs.',
            'required_headcount' => 2,
            'open_date' => now()->toDateString(),
            'target_hiring_date' => now()->addMonth()->toDateString(),
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'closed');
});

test('vacancy can be closed but cannot be closed again', function () {
    $department = recruitmentDepartment();
    [$admin, $token] = recruitmentAdmin();

    $vacancy = recruitmentVacancyRecord($admin, $department);

    $this->withToken($token)
        ->postJson("/api/recruitment/vacancies/{$vacancy->id}/close")
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'closed');

    $this->withToken($token)
        ->postJson("/api/recruitment/vacancies/{$vacancy->id}/close")
        ->assertForbidden();

    expect(
        Activity::query()
            ->where('log_name', 'recruitment_vacancies')
            ->where('subject_id', $vacancy->id)
            ->pluck('event')
            ->all()
    )->toEqual(['close']);
});

test('user without recruitment.vacancies.close permission cannot close a vacancy', function () {
    $department = recruitmentDepartment();
    [$admin] = recruitmentAdmin();
    [, $token] = recruitmentUser(['recruitment.vacancies.view', 'recruitment.vacancies.update']);

    $vacancy = recruitmentVacancyRecord($admin, $department);

    $this->withToken($token)
        ->postJson("/api/recruitment/vacancies/{$vacancy->id}/close")
        ->assertForbidden();
});

test('process due vacancies opens scheduled and closes due open vacancies', function () {
    $department = recruitmentDepartment();
    [$admin] = recruitmentAdmin();
    $today = now()->toDateString();

    $toOpen = recruitmentVacancyRecord($admin, $department, [
        'title' => 'Due to open',
        'open_date' => $today,
        'status' => 'scheduled',
    ]);

    $toClose = recruitmentVacancyRecord($admin, $department, [
        'title' => 'Due to close',
        'open_date' => now()->subWeek()->toDateString(),
        'close_date' => $today,
        'status' => 'open',
    ]);

    $stillScheduled = recruitmentVacancyRecord($admin, $department, [
        'title' => 'Still scheduled',
        'open_date' => now()->addWeek()->toDateString(),
        'status' => 'scheduled',
    ]);

    Artisan::call('recruitment:process-due-vacancies', ['--date' => $today]);

    expect($toOpen->fresh()->status)->toBe('open')
        ->and($toClose->fresh()->status)->toBe('closed')
        ->and($stillScheduled->fresh()->status)->toBe('scheduled');

    expect(
        Activity::query()
            ->where('log_name', 'recruitment_vacancies')
            ->whereIn('subject_id', [$toOpen->id, $toClose->id])
            ->pluck('event')
            ->all()
    )->toEqualCanonicalizing(['scheduled_open', 'scheduled_close']);
});

test('close-due alias also processes open and close', function () {
    $department = recruitmentDepartment();
    [$admin] = recruitmentAdmin();
    $today = now()->toDateString();

    $vacancy = recruitmentVacancyRecord($admin, $department, [
        'open_date' => now()->subDays(2)->toDateString(),
        'close_date' => $today,
        'status' => 'open',
    ]);

    Artisan::call('recruitment:close-due-vacancies', ['--date' => $today]);

    expect($vacancy->fresh()->status)->toBe('closed');
});

test('vacancy descriptions must contain visible text on creation and update', function (string $description) {
    $department = recruitmentDepartment();
    [$admin, $token] = recruitmentAdmin();
    $payload = [
        'title' => 'Backend Developer',
        'department_id' => $department->id,
        'description' => '<p>Build APIs.</p>',
        'required_headcount' => 2,
        'open_date' => now()->toDateString(),
        'target_hiring_date' => now()->toDateString(),
    ];
    $vacancy = recruitmentVacancyRecord($admin, $department, [
        'description' => '<p>Build APIs.</p>',
        'target_hiring_date' => now()->toDateString(),
    ]);
    $invalid = array_merge($payload, ['description' => $description]);

    $this->withToken($token)->postJson('/api/recruitment/vacancies', $invalid)
        ->assertUnprocessable()->assertJsonValidationErrors('description');
    $this->withToken($token)->putJson("/api/recruitment/vacancies/{$vacancy->id}", $invalid)
        ->assertUnprocessable()->assertJsonValidationErrors('description');
    expect($vacancy->fresh()->description)->toBe('<p>Build APIs.</p>');
})->with(['<p><br></p>', '<p> &nbsp; </p>', '<ul><li><br></li></ul>']);
