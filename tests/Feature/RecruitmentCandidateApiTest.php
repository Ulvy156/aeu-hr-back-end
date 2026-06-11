<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentVacancy;
use App\Models\User;
use App\Support\FileStorage;
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

/**
 * @return array{0: User, 1: string}
 */
function recruitmentCandidateAdmin(): array
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');

    return [$user, $user->createToken('admin-device')->plainTextToken];
}

/**
 * @param  array<int, string>  $permissions
 * @return array{0: User, 1: string}
 */
function recruitmentCandidateUser(array $permissions = []): array
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
function recruitmentCandidateVacancy(array $overrides = [], ?User $creator = null): RecruitmentVacancy
{
    $department = Department::query()->create([
        'name' => 'Engineering '.uniqid(),
        'status' => 'active',
    ]);

    $creator ??= User::factory()->create();

    return RecruitmentVacancy::query()->create(array_merge([
        'title' => 'Backend Developer',
        'department_id' => $department->id,
        'description' => 'Build APIs.',
        'required_headcount' => 2,
        'filled_headcount' => 0,
        'target_hiring_date' => now()->addMonth()->toDateString(),
        'status' => 'open',
        'created_by' => $creator->id,
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function recruitmentCandidateRecord(RecruitmentVacancy $vacancy, User $creator, array $overrides = []): RecruitmentCandidate
{
    $cv = UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf');
    $path = FileStorage::disk()->putFile('recruitment-cvs', $cv);

    return RecruitmentCandidate::query()->create(array_merge([
        'vacancy_id' => $vacancy->id,
        'full_name' => 'Jane Doe',
        'phone' => '012345678',
        'email' => 'jane@example.com',
        'source' => 'linkedin',
        'cv_path' => $path,
        'cv_name' => 'resume.pdf',
        'cv_size' => $cv->getSize(),
        'status' => 'new',
        'created_by' => $creator->id,
    ], $overrides));
}

test('unauthenticated user cannot access candidates', function () {
    $this->getJson('/api/recruitment/candidates')->assertUnauthorized();
});

test('candidate can be created with a cv upload under an open vacancy', function () {
    [$admin, $token] = recruitmentCandidateAdmin();
    $vacancy = recruitmentCandidateVacancy([], $admin);

    $response = $this->withToken($token)
        ->post('/api/recruitment/candidates', [
            'vacancy_id' => $vacancy->id,
            'full_name' => 'Jane Doe',
            'phone' => '012345678',
            'email' => 'jane@example.com',
            'source' => 'linkedin',
            'cv' => UploadedFile::fake()->create('resume.pdf', 200, 'application/pdf'),
        ], ['Accept' => 'application/json']);

    $response->assertCreated()
        ->assertJsonPath('data.full_name', 'Jane Doe')
        ->assertJsonPath('data.status', 'new')
        ->assertJsonPath('data.vacancy.id', $vacancy->id)
        ->assertJsonPath('data.cv.name', 'resume.pdf')
        ->assertJsonPath('data.creator.id', $admin->id);

    $candidate = RecruitmentCandidate::query()->findOrFail($response->json('data.id'));
    Storage::disk(config('filesystems.cloud'))->assertExists($candidate->cv_path);

    expect(
        Activity::query()->where('log_name', 'recruitment_candidates')->pluck('event')->all()
    )->toEqual(['create']);
});

test('cv is required and must be a pdf file', function () {
    [, $token] = recruitmentCandidateAdmin();
    $vacancy = recruitmentCandidateVacancy();

    $this->withToken($token)
        ->post('/api/recruitment/candidates', [
            'vacancy_id' => $vacancy->id,
            'full_name' => 'Jane Doe',
            'phone' => '012345678',
            'source' => 'linkedin',
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cv');

    $this->withToken($token)
        ->post('/api/recruitment/candidates', [
            'vacancy_id' => $vacancy->id,
            'full_name' => 'Jane Doe',
            'phone' => '012345678',
            'source' => 'linkedin',
            'cv' => UploadedFile::fake()->create('resume.docx', 100, 'application/msword'),
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cv');
});

test('candidate cannot be created under a closed vacancy', function () {
    [, $token] = recruitmentCandidateAdmin();
    $vacancy = recruitmentCandidateVacancy(['status' => 'closed']);

    $this->withToken($token)
        ->post('/api/recruitment/candidates', [
            'vacancy_id' => $vacancy->id,
            'full_name' => 'Jane Doe',
            'phone' => '012345678',
            'source' => 'linkedin',
            'cv' => UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('vacancy_id');
});

test('duplicate phone is blocked within the same vacancy but allowed in a different vacancy', function () {
    [, $token] = recruitmentCandidateAdmin();
    $vacancyA = recruitmentCandidateVacancy();
    $vacancyB = recruitmentCandidateVacancy();

    $payload = fn (int $vacancyId): array => [
        'vacancy_id' => $vacancyId,
        'full_name' => 'Jane Doe',
        'phone' => '012345678',
        'source' => 'linkedin',
        'cv' => UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf'),
    ];

    $this->withToken($token)
        ->post('/api/recruitment/candidates', $payload($vacancyA->id), ['Accept' => 'application/json'])
        ->assertCreated();

    $this->withToken($token)
        ->post('/api/recruitment/candidates', $payload($vacancyA->id), ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('phone');

    $this->withToken($token)
        ->post('/api/recruitment/candidates', $payload($vacancyB->id), ['Accept' => 'application/json'])
        ->assertCreated();
});

test('user without recruitment.candidates.create permission cannot create a candidate', function () {
    [$admin] = recruitmentCandidateAdmin();
    $vacancy = recruitmentCandidateVacancy([], $admin);
    [, $token] = recruitmentCandidateUser(['recruitment.candidates.view']);

    $this->withToken($token)
        ->post('/api/recruitment/candidates', [
            'vacancy_id' => $vacancy->id,
            'full_name' => 'Jane Doe',
            'phone' => '012345678',
            'source' => 'linkedin',
            'cv' => UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])
        ->assertForbidden();
});

test('candidates can be listed and filtered', function () {
    [$admin, $token] = recruitmentCandidateAdmin();
    $vacancy = recruitmentCandidateVacancy([], $admin);

    recruitmentCandidateRecord($vacancy, $admin, ['full_name' => 'Alice', 'phone' => '011111111']);
    recruitmentCandidateRecord($vacancy, $admin, ['full_name' => 'Bob', 'phone' => '022222222', 'status' => 'shortlisted']);

    $this->withToken($token)
        ->getJson("/api/recruitment/candidates?vacancy={$vacancy->id}&status=shortlisted")
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.full_name', 'Bob');
});

test('candidate can be updated while not hired', function () {
    [$admin, $token] = recruitmentCandidateAdmin();
    $vacancy = recruitmentCandidateVacancy([], $admin);
    $candidate = recruitmentCandidateRecord($vacancy, $admin);

    $interviewerUser = User::factory()->create(['name' => 'John HR']);
    $interviewer = Employee::query()->create([
        'user_id' => $interviewerUser->id,
        'employee_id' => 'EMP-00001',
        'full_name' => 'John HR',
        'join_date' => '2026-05-01',
        'base_salary' => '1000.00',
        'employment_status' => 'active',
    ]);

    $this->withToken($token)
        ->putJson("/api/recruitment/candidates/{$candidate->id}", [
            'full_name' => 'Jane Smith',
            'phone' => $candidate->phone,
            'email' => $candidate->email,
            'source' => 'referral',
            'interview_date' => now()->addDays(3)->toDateString(),
            'interviewer_id' => $interviewer->id,
            'notes' => 'Strong candidate',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.full_name', 'Jane Smith')
        ->assertJsonPath('data.source', 'referral')
        ->assertJsonPath('data.interviewer.id', $interviewer->id)
        ->assertJsonPath('data.interviewer.full_name', 'John HR');
});

test('changing status to a final outcome requires an outcome reason', function () {
    [$admin, $token] = recruitmentCandidateAdmin();
    $vacancy = recruitmentCandidateVacancy([], $admin);
    $candidate = recruitmentCandidateRecord($vacancy, $admin);

    $this->withToken($token)
        ->postJson("/api/recruitment/candidates/{$candidate->id}/status", [
            'status' => 'company_rejected',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('outcome_reason');

    $this->withToken($token)
        ->postJson("/api/recruitment/candidates/{$candidate->id}/status", [
            'status' => 'company_rejected',
            'outcome_reason' => 'Not enough experience.',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'company_rejected')
        ->assertJsonPath('data.outcome_reason', 'Not enough experience.');
});

test('marking a candidate as hired increments vacancy filled headcount and makes candidate read only', function () {
    [$admin, $token] = recruitmentCandidateAdmin();
    $vacancy = recruitmentCandidateVacancy([], $admin);
    $candidate = recruitmentCandidateRecord($vacancy, $admin);

    $this->withToken($token)
        ->postJson("/api/recruitment/candidates/{$candidate->id}/status", [
            'status' => 'hired',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'hired');

    expect($vacancy->fresh()->filled_headcount)->toBe(1);

    $this->withToken($token)
        ->putJson("/api/recruitment/candidates/{$candidate->id}", [
            'full_name' => 'Jane Smith',
            'phone' => $candidate->phone,
            'source' => $candidate->source,
        ])
        ->assertUnprocessable();

    $this->withToken($token)
        ->postJson("/api/recruitment/candidates/{$candidate->id}/status", [
            'status' => 'offer_accepted',
        ])
        ->assertUnprocessable();

    expect(
        Activity::query()
            ->where('log_name', 'recruitment_candidates')
            ->where('subject_id', $candidate->id)
            ->pluck('event')
            ->all()
    )->toEqual(['status_change', 'hire']);
});

test('changing status to hired requires recruitment.candidates.hire permission', function () {
    [$admin] = recruitmentCandidateAdmin();
    $vacancy = recruitmentCandidateVacancy([], $admin);
    $candidate = recruitmentCandidateRecord($vacancy, $admin);

    [, $token] = recruitmentCandidateUser(['recruitment.candidates.view', 'recruitment.candidates.update']);

    $this->withToken($token)
        ->postJson("/api/recruitment/candidates/{$candidate->id}/status", [
            'status' => 'hired',
        ])
        ->assertForbidden();

    expect($candidate->fresh()->status)->toBe('new');
    expect($vacancy->fresh()->filled_headcount)->toBe(0);
});
