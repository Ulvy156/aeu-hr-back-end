<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeUpgradeRequest;
use App\Models\EmploymentHistory;
use App\Models\Position;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Storage::fake(config('filesystems.cloud'));
});

function upgradeActor(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function upgradeEmployee(array $overrides = []): Employee
{
    static $counter = 0;
    $counter++;

    $linkedUser = User::factory()->create([
        'name' => "Upgrade Employee {$counter}",
        'email' => "upgrade.employee{$counter}@example.com",
        'status' => 'active',
    ]);
    $linkedUser->assignRole('employee');

    return Employee::query()->create(array_merge([
        'user_id' => $linkedUser->id,
        'employee_id' => 'EMP-'.str_pad((string) $counter, 5, '0', STR_PAD_LEFT),
        'full_name' => "Upgrade Employee {$counter}",
        'join_date' => '2026-01-01',
        'base_salary' => '1000.00',
        'employment_status' => 'full-time',
    ], $overrides));
}

/**
 * @return array{0: Department, 1: Position}
 */
function upgradeDepartmentAndPosition(string $departmentName, string $positionName): array
{
    $department = Department::query()->create([
        'name' => $departmentName,
        'status' => 'active',
    ]);

    $position = Position::query()->create([
        'name' => $positionName,
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    return [$department, $position];
}

test('hr can create a single-field upgrade request for base salary', function () {
    $employee = upgradeEmployee(['base_salary' => '1000.00']);
    Sanctum::actingAs(upgradeActor('hr'));

    $this->postJson('/api/employee-upgrade-requests', [
        'employee_id' => $employee->id,
        'proposed_values' => [
            'base_salary' => '1500.00',
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.current_values', ['base_salary' => '1000.00'])
        ->assertJsonPath('data.proposed_values', ['base_salary' => '1500.00']);

    expect($employee->fresh()->base_salary)->toBe('1000.00')
        ->and(EmploymentHistory::query()->count())->toBe(0);
});

test('hr can create a multi-field upgrade request for department position and salary', function () {
    [$deptA, $posA] = upgradeDepartmentAndPosition('Sales', 'Sales Rep');
    [$deptB, $posB] = upgradeDepartmentAndPosition('Engineering', 'Engineer');

    $employee = upgradeEmployee([
        'department_id' => $deptA->id,
        'position_id' => $posA->id,
        'base_salary' => '1000.00',
    ]);

    Sanctum::actingAs(upgradeActor('hr'));

    $response = $this->postJson('/api/employee-upgrade-requests', [
        'employee_id' => $employee->id,
        'proposed_values' => [
            'department_id' => $deptB->id,
            'position_id' => $posB->id,
            'base_salary' => '2000.00',
        ],
    ]);

    $response->assertCreated();

    expect($response->json('data.current_values'))->toMatchArray([
        'department_id' => ['id' => $deptA->id, 'name' => $deptA->name],
        'position_id' => ['id' => $posA->id, 'name' => $posA->name],
        'base_salary' => '1000.00',
    ])
        ->and($response->json('data.proposed_values'))->toMatchArray([
            'department_id' => ['id' => $deptB->id, 'name' => $deptB->name],
            'position_id' => ['id' => $posB->id, 'name' => $posB->name],
            'base_salary' => '2000.00',
        ]);
});

test('upgrade request creation fails when no proposed value differs from current data', function () {
    $employee = upgradeEmployee(['base_salary' => '1000.00']);
    Sanctum::actingAs(upgradeActor('hr'));

    $this->postJson('/api/employee-upgrade-requests', [
        'employee_id' => $employee->id,
        'proposed_values' => [
            'base_salary' => '1000.00',
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('proposed_values');
});

test('upgrade request validates employment_status and last_working_date combination', function () {
    $employee = upgradeEmployee(['employment_status' => 'full-time']);
    Sanctum::actingAs(upgradeActor('hr'));

    $this->postJson('/api/employee-upgrade-requests', [
        'employee_id' => $employee->id,
        'proposed_values' => [
            'employment_status' => 'resigned',
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('proposed_values.last_working_date');

    $this->postJson('/api/employee-upgrade-requests', [
        'employee_id' => $employee->id,
        'proposed_values' => [
            'employment_status' => 'full-time',
            'last_working_date' => '2026-06-01',
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('proposed_values.last_working_date');
});

test('upgrade request validates that the proposed position belongs to the resulting department', function () {
    [$deptA] = upgradeDepartmentAndPosition('Sales', 'Sales Rep');
    [, $posB] = upgradeDepartmentAndPosition('Engineering', 'Engineer');

    $employee = upgradeEmployee([
        'department_id' => $deptA->id,
        'position_id' => null,
    ]);

    Sanctum::actingAs(upgradeActor('hr'));

    $this->postJson('/api/employee-upgrade-requests', [
        'employee_id' => $employee->id,
        'proposed_values' => [
            'position_id' => $posB->id,
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('proposed_values.position_id');
});

test('employee role cannot create upgrade requests and ceo lacks create permission', function () {
    $employee = upgradeEmployee(['base_salary' => '1000.00']);

    Sanctum::actingAs(upgradeActor('employee'));
    $this->postJson('/api/employee-upgrade-requests', [
        'employee_id' => $employee->id,
        'proposed_values' => ['base_salary' => '1500.00'],
    ])->assertForbidden();

    Sanctum::actingAs(upgradeActor('ceo'));
    $this->postJson('/api/employee-upgrade-requests', [
        'employee_id' => $employee->id,
        'proposed_values' => ['base_salary' => '1500.00'],
    ])->assertForbidden();
});

test('ceo can approve a pending upgrade request and it updates the employee and employment history', function () {
    $employee = upgradeEmployee([
        'employment_status' => 'full-time',
        'base_salary' => '1000.00',
        'join_date' => '2026-01-01',
    ]);

    Sanctum::actingAs(upgradeActor('hr'));

    $createResponse = $this->postJson('/api/employee-upgrade-requests', [
        'employee_id' => $employee->id,
        'proposed_values' => [
            'employment_status' => 'probation',
            'base_salary' => '1500.00',
        ],
    ])->assertCreated();

    $id = $createResponse->json('data.id');

    Sanctum::actingAs(upgradeActor('ceo'));

    $this->postJson("/api/employee-upgrade-requests/{$id}/approve")
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'approved');

    $employee->refresh();
    $employee->load('user');

    expect($employee->employment_status)->toBe('probation')
        ->and($employee->base_salary)->toBe('1500.00')
        ->and($employee->probation_end_date->toDateString())->toBe('2026-04-01')
        ->and($employee->user->status)->toBe('active');

    $historyFields = EmploymentHistory::query()->where('employee_id', $employee->id)->pluck('field')->all();

    expect($historyFields)->toContain('base_salary')
        ->and($historyFields)->toContain('employment_status')
        ->and($historyFields)->toContain('probation_end_date')
        ->and($historyFields)->not->toContain('department_id')
        ->and($historyFields)->not->toContain('position_id');

    expect(Activity::query()->where('log_name', 'employee_upgrade_requests')->where('description', 'approve')->exists())->toBeTrue()
        ->and(Activity::query()->where('log_name', 'employees')->where('description', 'update')->exists())->toBeTrue();
});

test('ceo can approve a pending upgrade request to intern and it derives intern_end_date', function () {
    $employee = upgradeEmployee([
        'employment_status' => 'full-time',
        'base_salary' => '1000.00',
        'join_date' => '2026-01-01',
    ]);

    Sanctum::actingAs(upgradeActor('hr'));

    $createResponse = $this->postJson('/api/employee-upgrade-requests', [
        'employee_id' => $employee->id,
        'proposed_values' => [
            'employment_status' => 'intern',
            'base_salary' => '1500.00',
        ],
    ])->assertCreated();

    $id = $createResponse->json('data.id');

    Sanctum::actingAs(upgradeActor('ceo'));

    $this->postJson("/api/employee-upgrade-requests/{$id}/approve")
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'approved');

    $employee->refresh();
    $employee->load('user');

    expect($employee->employment_status->value)->toBe('intern')
        ->and($employee->base_salary)->toBe('1500.00')
        ->and($employee->intern_end_date->toDateString())->toBe('2026-04-01')
        ->and($employee->user->status->value)->toBe('active');

    $historyFields = EmploymentHistory::query()->where('employee_id', $employee->id)->pluck('field')->all();

    expect($historyFields)->toContain('base_salary')
        ->and($historyFields)->toContain('employment_status')
        ->and($historyFields)->toContain('intern_end_date')
        ->and($historyFields)->not->toContain('department_id')
        ->and($historyFields)->not->toContain('position_id');
});

test('ceo can reject a pending upgrade request with a reason and rejecting without one fails', function () {
    $employee = upgradeEmployee(['base_salary' => '1000.00']);

    Sanctum::actingAs(upgradeActor('hr'));

    $createResponse = $this->postJson('/api/employee-upgrade-requests', [
        'employee_id' => $employee->id,
        'proposed_values' => ['base_salary' => '1500.00'],
    ])->assertCreated();

    $id = $createResponse->json('data.id');

    Sanctum::actingAs(upgradeActor('ceo'));

    $this->postJson("/api/employee-upgrade-requests/{$id}/reject", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rejection_reason');

    $this->postJson("/api/employee-upgrade-requests/{$id}/reject", [
        'rejection_reason' => 'Budget not approved.',
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.rejection_reason', 'Budget not approved.');

    expect($employee->fresh()->base_salary)->toBe('1000.00')
        ->and(EmploymentHistory::query()->count())->toBe(0);
});

test('requester can cancel their own pending upgrade request but other hr users cannot', function () {
    $employee = upgradeEmployee(['base_salary' => '1000.00']);
    $hr1 = upgradeActor('hr');
    $hr2 = upgradeActor('hr');

    Sanctum::actingAs($hr1);

    $createResponse = $this->postJson('/api/employee-upgrade-requests', [
        'employee_id' => $employee->id,
        'proposed_values' => ['base_salary' => '1500.00'],
    ])->assertCreated();

    $id = $createResponse->json('data.id');

    Sanctum::actingAs($hr2);

    $this->postJson("/api/employee-upgrade-requests/{$id}/cancel")
        ->assertForbidden();

    Sanctum::actingAs($hr1);

    $this->postJson("/api/employee-upgrade-requests/{$id}/cancel")
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'cancelled');
});

test('approve reject and cancel fail with 422 on a non-pending upgrade request', function () {
    $employee = upgradeEmployee(['base_salary' => '1000.00']);
    $hr = upgradeActor('hr');
    $ceo = upgradeActor('ceo');

    Sanctum::actingAs($hr);

    $createResponse = $this->postJson('/api/employee-upgrade-requests', [
        'employee_id' => $employee->id,
        'proposed_values' => ['base_salary' => '1500.00'],
    ])->assertCreated();

    $id = $createResponse->json('data.id');

    Sanctum::actingAs($ceo);

    $this->postJson("/api/employee-upgrade-requests/{$id}/approve")->assertSuccessful();

    $this->postJson("/api/employee-upgrade-requests/{$id}/approve")
        ->assertStatus(422);

    $this->postJson("/api/employee-upgrade-requests/{$id}/reject", [
        'rejection_reason' => 'too late',
    ])->assertStatus(422);

    Sanctum::actingAs($hr);

    $this->postJson("/api/employee-upgrade-requests/{$id}/cancel")
        ->assertStatus(422);
});

test('hr lacks permission to approve or reject upgrade requests', function () {
    $employee = upgradeEmployee(['base_salary' => '1000.00']);
    Sanctum::actingAs(upgradeActor('hr'));

    $createResponse = $this->postJson('/api/employee-upgrade-requests', [
        'employee_id' => $employee->id,
        'proposed_values' => ['base_salary' => '1500.00'],
    ])->assertCreated();

    $id = $createResponse->json('data.id');

    $this->postJson("/api/employee-upgrade-requests/{$id}/approve")
        ->assertForbidden();

    $this->postJson("/api/employee-upgrade-requests/{$id}/reject", [
        'rejection_reason' => 'no',
    ])->assertForbidden();
});

test('ceo sees all upgrade requests while hr only sees their own', function () {
    $employee = upgradeEmployee(['base_salary' => '1000.00']);
    $hr1 = upgradeActor('hr');
    $hr2 = upgradeActor('hr');
    $ceo = upgradeActor('ceo');

    Sanctum::actingAs($hr1);
    $this->postJson('/api/employee-upgrade-requests', [
        'employee_id' => $employee->id,
        'proposed_values' => ['base_salary' => '1500.00'],
    ])->assertCreated();

    Sanctum::actingAs($hr2);
    $this->postJson('/api/employee-upgrade-requests', [
        'employee_id' => $employee->id,
        'proposed_values' => ['base_salary' => '1600.00'],
    ])->assertCreated();

    Sanctum::actingAs($ceo);
    $this->getJson('/api/employee-upgrade-requests')
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 2);

    Sanctum::actingAs($hr1);
    $this->getJson('/api/employee-upgrade-requests')
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1);
});

test('effective_date on the upgrade request propagates to the employment history record', function () {
    $employee = upgradeEmployee(['base_salary' => '1000.00']);

    Sanctum::actingAs(upgradeActor('hr'));

    $createResponse = $this->postJson('/api/employee-upgrade-requests', [
        'employee_id' => $employee->id,
        'effective_date' => '2026-07-01',
        'proposed_values' => ['base_salary' => '1500.00'],
    ])->assertCreated();

    $id = $createResponse->json('data.id');

    Sanctum::actingAs(upgradeActor('ceo'));

    $this->postJson("/api/employee-upgrade-requests/{$id}/approve")->assertSuccessful();

    $history = EmploymentHistory::query()
        ->where('employee_id', $employee->id)
        ->where('field', 'base_salary')
        ->firstOrFail();

    expect($history->effective_date->toDateString())->toBe('2026-07-01');
});

test('employee update endpoint still works and does not create employment history rows', function () {
    [$department, $position] = upgradeDepartmentAndPosition('Operations', 'Operations Lead');
    $manager = upgradeEmployee();
    $employee = upgradeEmployee([
        'department_id' => $department->id,
        'position_id' => $position->id,
        'manager_id' => $manager->id,
        'base_salary' => '1000.00',
    ]);

    Sanctum::actingAs(upgradeActor('hr'));

    $this->putJson("/api/employees/{$employee->id}", [
        'full_name' => $employee->full_name,
        'department_id' => $department->id,
        'position_id' => $position->id,
        'manager_id' => $manager->id,
        'join_date' => $employee->join_date->toDateString(),
        'base_salary' => '1200.00',
        'employment_status' => 'full-time',
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.base_salary', '1200.00');

    expect(EmploymentHistory::query()->count())->toBe(0);
});

test('creating an upgrade request with 1 to 3 attachments stores file metadata and urls', function () {
    $employee = upgradeEmployee(['base_salary' => '1000.00']);
    Sanctum::actingAs(upgradeActor('hr'));

    $response = $this->post('/api/employee-upgrade-requests', [
        'employee_id' => $employee->id,
        'proposed_values' => [
            'base_salary' => '1500.00',
        ],
        'attachments' => [
            UploadedFile::fake()->create('promotion-letter.pdf', 100, 'application/pdf'),
            UploadedFile::fake()->image('signed-letter.jpg'),
            UploadedFile::fake()->create('contract.docx', 50, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
        ],
    ], ['Accept' => 'application/json']);

    $response->assertCreated();

    $attachments = $response->json('data.attachments');

    expect($attachments)->toHaveCount(3);

    foreach ($attachments as $attachment) {
        expect($attachment)->toHaveKeys(['name', 'size', 'url'])
            ->and($attachment['url'])->not->toBeNull();
    }

    $upgradeRequest = EmployeeUpgradeRequest::query()->findOrFail($response->json('data.id'));

    foreach ($upgradeRequest->attachments as $stored) {
        Storage::disk(config('filesystems.cloud'))->assertExists($stored['path']);
    }
});

test('upgrade request rejects more than 3 attachments and disallowed file types', function () {
    $employee = upgradeEmployee(['base_salary' => '1000.00']);
    Sanctum::actingAs(upgradeActor('hr'));

    $this->post('/api/employee-upgrade-requests', [
        'employee_id' => $employee->id,
        'proposed_values' => ['base_salary' => '1500.00'],
        'attachments' => [
            UploadedFile::fake()->create('one.pdf', 10, 'application/pdf'),
            UploadedFile::fake()->create('two.pdf', 10, 'application/pdf'),
            UploadedFile::fake()->create('three.pdf', 10, 'application/pdf'),
            UploadedFile::fake()->create('four.pdf', 10, 'application/pdf'),
        ],
    ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('attachments');

    $this->post('/api/employee-upgrade-requests', [
        'employee_id' => $employee->id,
        'proposed_values' => ['base_salary' => '1500.00'],
        'attachments' => [
            UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload'),
        ],
    ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('attachments.0');
});

test('upgrade request without attachments returns an empty attachments array', function () {
    $employee = upgradeEmployee(['base_salary' => '1000.00']);
    Sanctum::actingAs(upgradeActor('hr'));

    $this->postJson('/api/employee-upgrade-requests', [
        'employee_id' => $employee->id,
        'proposed_values' => ['base_salary' => '1500.00'],
    ])
        ->assertCreated()
        ->assertJsonPath('data.attachments', []);
});

test('hr can create an upgrade request proposing a new manager', function () {
    $manager = upgradeEmployee(['full_name' => 'Manager One']);
    $employee = upgradeEmployee(['manager_id' => null]);

    Sanctum::actingAs(upgradeActor('hr'));

    $this->postJson('/api/employee-upgrade-requests', [
        'employee_id' => $employee->id,
        'proposed_values' => [
            'manager_id' => $manager->id,
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.current_values', ['manager_id' => null])
        ->assertJsonPath('data.proposed_values', ['manager_id' => ['id' => $manager->id, 'name' => $manager->full_name]]);

    expect($employee->fresh()->manager_id)->toBeNull()
        ->and(EmploymentHistory::query()->count())->toBe(0);
});

test('upgrade request still resolves department name after the department is soft deleted', function () {
    [$oldDepartment] = upgradeDepartmentAndPosition('Legacy Dept', 'Legacy Role');
    [$newDepartment] = upgradeDepartmentAndPosition('New Dept', 'New Role');
    $employee = upgradeEmployee(['department_id' => $oldDepartment->id]);

    Sanctum::actingAs(upgradeActor('hr'));

    $createResponse = $this->postJson('/api/employee-upgrade-requests', [
        'employee_id' => $employee->id,
        'proposed_values' => ['department_id' => $newDepartment->id],
    ])->assertCreated();

    $id = $createResponse->json('data.id');

    $oldDepartment->delete();

    $this->getJson("/api/employee-upgrade-requests/{$id}")
        ->assertSuccessful()
        ->assertJsonPath('data.current_values.department_id', ['id' => $oldDepartment->id, 'name' => 'Legacy Dept'])
        ->assertJsonPath('data.proposed_values.department_id', ['id' => $newDepartment->id, 'name' => 'New Dept']);
});

test('upgrade request rejects an employee being proposed as their own manager', function () {
    $employee = upgradeEmployee();

    Sanctum::actingAs(upgradeActor('hr'));

    $this->postJson('/api/employee-upgrade-requests', [
        'employee_id' => $employee->id,
        'proposed_values' => [
            'manager_id' => $employee->id,
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('proposed_values.manager_id');
});

test('upgrade request rejects a manager change that would create a circular reporting relationship', function () {
    $employeeA = upgradeEmployee(['full_name' => 'Employee A']);
    $employeeB = upgradeEmployee(['full_name' => 'Employee B', 'manager_id' => $employeeA->id]);

    Sanctum::actingAs(upgradeActor('hr'));

    // Proposing that A (B's manager) reports to B would create a cycle.
    $this->postJson('/api/employee-upgrade-requests', [
        'employee_id' => $employeeA->id,
        'proposed_values' => [
            'manager_id' => $employeeB->id,
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('proposed_values.manager_id');
});

test('ceo can approve a manager_id upgrade request and it updates the employee and employment history', function () {
    $manager = upgradeEmployee(['full_name' => 'Manager Two']);
    $employee = upgradeEmployee(['manager_id' => null]);

    Sanctum::actingAs(upgradeActor('hr'));

    $createResponse = $this->postJson('/api/employee-upgrade-requests', [
        'employee_id' => $employee->id,
        'proposed_values' => [
            'manager_id' => $manager->id,
        ],
    ])->assertCreated();

    $id = $createResponse->json('data.id');

    Sanctum::actingAs(upgradeActor('ceo'));

    $this->postJson("/api/employee-upgrade-requests/{$id}/approve")
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'approved');

    expect($employee->fresh()->manager_id)->toBe($manager->id);

    $history = EmploymentHistory::query()
        ->where('employee_id', $employee->id)
        ->where('field', 'manager_id')
        ->firstOrFail();

    expect($history->old_value)->toBeNull()
        ->and($history->new_value)->toBe(['id' => $manager->id, 'name' => 'Manager Two']);
});
