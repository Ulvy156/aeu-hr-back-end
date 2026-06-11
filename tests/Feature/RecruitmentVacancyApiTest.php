<?php

use App\Models\Department;
use App\Models\RecruitmentVacancy;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

test('unauthenticated user cannot access vacancies', function () {
    $this->getJson('/api/recruitment/vacancies')->assertUnauthorized();
});

test('admin can create a vacancy with default open status and zero filled headcount', function () {
    $department = recruitmentDepartment();
    [$admin, $token] = recruitmentAdmin();

    $this->withToken($token)
        ->postJson('/api/recruitment/vacancies', [
            'title' => 'Backend Developer',
            'department_id' => $department->id,
            'description' => 'Build and maintain APIs.',
            'required_headcount' => 2,
            'target_hiring_date' => now()->addMonth()->toDateString(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Backend Developer')
        ->assertJsonPath('data.status', 'open')
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

test('vacancy creation validates required headcount and required fields', function () {
    $department = recruitmentDepartment();
    [, $token] = recruitmentAdmin();

    $this->withToken($token)
        ->postJson('/api/recruitment/vacancies', [
            'title' => 'Backend Developer',
            'department_id' => $department->id,
            'description' => 'Build and maintain APIs.',
            'required_headcount' => 0,
            'target_hiring_date' => now()->addMonth()->toDateString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('required_headcount');

    $this->withToken($token)
        ->postJson('/api/recruitment/vacancies', [
            'title' => 'Backend Developer',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['department_id', 'description', 'required_headcount', 'target_hiring_date']);
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
            'target_hiring_date' => now()->addMonth()->toDateString(),
        ])
        ->assertForbidden();
});

test('vacancies can be listed, filtered and viewed', function () {
    $engineering = recruitmentDepartment(['name' => 'Engineering']);
    $sales = recruitmentDepartment(['name' => 'Sales']);
    [$admin, $token] = recruitmentAdmin();

    $open = RecruitmentVacancy::query()->create([
        'title' => 'Backend Developer',
        'department_id' => $engineering->id,
        'description' => 'Build APIs.',
        'required_headcount' => 2,
        'filled_headcount' => 0,
        'target_hiring_date' => now()->addMonth()->toDateString(),
        'status' => 'open',
        'created_by' => $admin->id,
    ]);

    RecruitmentVacancy::query()->create([
        'title' => 'Sales Executive',
        'department_id' => $sales->id,
        'description' => 'Drive sales.',
        'required_headcount' => 1,
        'filled_headcount' => 1,
        'target_hiring_date' => now()->addMonth()->toDateString(),
        'status' => 'closed',
        'created_by' => $admin->id,
    ]);

    $this->withToken($token)
        ->getJson('/api/recruitment/vacancies?status=open')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Backend Developer');

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

test('vacancy can be updated while open', function () {
    $department = recruitmentDepartment();
    [$admin, $token] = recruitmentAdmin();

    $vacancy = RecruitmentVacancy::query()->create([
        'title' => 'Backend Developer',
        'department_id' => $department->id,
        'description' => 'Build APIs.',
        'required_headcount' => 2,
        'filled_headcount' => 0,
        'target_hiring_date' => now()->addMonth()->toDateString(),
        'status' => 'open',
        'created_by' => $admin->id,
    ]);

    $this->withToken($token)
        ->putJson("/api/recruitment/vacancies/{$vacancy->id}", [
            'title' => 'Senior Backend Developer',
            'department_id' => $department->id,
            'description' => 'Build and scale APIs.',
            'required_headcount' => 3,
            'target_hiring_date' => now()->addMonths(2)->toDateString(),
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.title', 'Senior Backend Developer')
        ->assertJsonPath('data.required_headcount', 3);
});

test('vacancy can be closed but cannot be closed again', function () {
    $department = recruitmentDepartment();
    [$admin, $token] = recruitmentAdmin();

    $vacancy = RecruitmentVacancy::query()->create([
        'title' => 'Backend Developer',
        'department_id' => $department->id,
        'description' => 'Build APIs.',
        'required_headcount' => 2,
        'filled_headcount' => 0,
        'target_hiring_date' => now()->addMonth()->toDateString(),
        'status' => 'open',
        'created_by' => $admin->id,
    ]);

    $this->withToken($token)
        ->postJson("/api/recruitment/vacancies/{$vacancy->id}/close")
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'closed');

    $this->withToken($token)
        ->postJson("/api/recruitment/vacancies/{$vacancy->id}/close")
        ->assertUnprocessable();

    expect(
        Activity::query()
            ->where('log_name', 'recruitment_vacancies')
            ->where('subject_id', $vacancy->id)
            ->pluck('event')
            ->all()
    )->toEqual(['create', 'close']);
});

test('user without recruitment.vacancies.close permission cannot close a vacancy', function () {
    $department = recruitmentDepartment();
    [$admin] = recruitmentAdmin();
    [, $token] = recruitmentUser(['recruitment.vacancies.view', 'recruitment.vacancies.update']);

    $vacancy = RecruitmentVacancy::query()->create([
        'title' => 'Backend Developer',
        'department_id' => $department->id,
        'description' => 'Build APIs.',
        'required_headcount' => 2,
        'filled_headcount' => 0,
        'target_hiring_date' => now()->addMonth()->toDateString(),
        'status' => 'open',
        'created_by' => $admin->id,
    ]);

    $this->withToken($token)
        ->postJson("/api/recruitment/vacancies/{$vacancy->id}/close")
        ->assertForbidden();
});
