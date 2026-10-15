<?php

use App\Exceptions\ApiException;
use App\Models\CompanySetting;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeUpgradeRequest;
use App\Models\EmploymentHistory;
use App\Models\PayrollBatch;
use App\Models\Position;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\EmployeeUpgradeRequestService;
use App\Services\PayrollService;
use App\Services\SalaryHistoryService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schedule;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00', 'Asia/Phnom_Penh'));
    $this->seed(RoleSeeder::class);
    $this->hr = User::factory()->create();
    $this->hr->assignRole('hr');
    $this->ceo = User::factory()->create();
    $this->ceo->assignRole('ceo');
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('employee');
    $this->employee = Employee::query()->create([
        'user_id' => $user->id, 'employee_id' => 'EMP-TEST-001', 'full_name' => 'Salary Employee',
        'join_date' => '2026-01-01', 'base_salary' => 400, 'employment_status' => 'full-time',
    ]);
    CompanySetting::query()->create([
        'company_name' => 'Test', 'working_start_time' => '08:00:00', 'working_end_time' => '17:00:00',
        'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
        'salary_currency' => 'USD', 'payroll_day_rate' => 30,
    ]);
});

function effectiveRequest(Employee $employee, User $requester, array $changes = ['base_salary' => 500]): EmployeeUpgradeRequest
{
    return app(EmployeeUpgradeRequestService::class)->create([
        'employee_id' => $employee->id, 'effective_date' => '2026-11-01', 'proposed_values' => $changes,
    ], null, $requester);
}

function correctSalaryPayload(Employee $employee, int $salary): array
{
    $department = Department::query()->create(['name' => 'Correction Department', 'status' => 'active']);
    $managerUser = User::factory()->create(['status' => 'active']);
    $manager = Employee::query()->create([
        'user_id' => $managerUser->id, 'employee_id' => 'EMP-CORRECTION-MANAGER', 'full_name' => 'Manager',
        'join_date' => '2027-01-01', 'base_salary' => 1000, 'employment_status' => 'full-time',
    ]);

    return [
        'department_id' => $department->id, 'manager_id' => $manager->id,
        'full_name' => $employee->full_name, 'join_date' => $employee->join_date->toDateString(),
        'base_salary' => $salary, 'employment_status' => 'full-time',
    ];
}

test('new promotions require exactly next months first day', function (?string $date) {
    Sanctum::actingAs($this->hr);
    $this->postJson('/api/employee-upgrade-requests', [
        'employee_id' => $this->employee->id, 'effective_date' => $date, 'proposed_values' => ['base_salary' => 500],
    ])->assertUnprocessable()->assertJsonValidationErrors('effective_date');
})->with([null, '2026-09-01', '2026-10-01', '2026-11-15', '2026-12-01', 'invalid']);

test('next month validation handles December and Cambodia month boundaries', function () {
    $this->travelTo(Carbon::parse('2026-12-31 23:59:00', 'Asia/Phnom_Penh'));
    Sanctum::actingAs($this->hr);
    $this->postJson('/api/employee-upgrade-requests', [
        'employee_id' => $this->employee->id, 'effective_date' => '2027-01-01', 'proposed_values' => ['base_salary' => 500],
    ])->assertCreated();
    $this->travelTo(Carbon::parse('2027-01-01 00:01:00', 'Asia/Phnom_Penh'));
    Sanctum::actingAs($this->ceo);
    $this->postJson('/api/employee-upgrade-requests/1/approve')->assertUnprocessable();
});

test('approval schedules changes and repeated due commands apply only once', function () {
    $request = effectiveRequest($this->employee, $this->hr);
    Sanctum::actingAs($this->ceo);
    $this->postJson("/api/employee-upgrade-requests/{$request->id}/approve")
        ->assertOk()->assertJsonPath('data.application_state', 'scheduled')->assertJsonPath('data.applied_at', null);
    $this->artisan('employees:apply-due-promotions')->assertSuccessful();
    expect($this->employee->fresh()->base_salary)->toBe('400.00')
        ->and(EmploymentHistory::query()->count())->toBe(0);

    $this->travelTo(Carbon::parse('2026-11-01 01:00:00', 'Asia/Phnom_Penh'));
    $this->artisan('employees:apply-due-promotions')->assertSuccessful();
    $this->artisan('employees:apply-due-promotions')->assertSuccessful();
    $history = EmploymentHistory::query()->sole();
    expect($this->employee->fresh()->base_salary)->toBe('500.00')
        ->and($request->fresh()->applied_at)->not->toBeNull()
        ->and($history->old_value['value'])->toBe(400.0)
        ->and($history->new_value['value'])->toBe(500.0)
        ->and($history->effective_date->toDateString())->toBe('2026-11-01');
});

test('only one approved unapplied request is allowed and CEO can cancel it', function () {
    $first = effectiveRequest($this->employee, $this->hr);
    $second = effectiveRequest($this->employee, $this->hr, ['base_salary' => 600]);
    Sanctum::actingAs($this->ceo);
    $this->postJson("/api/employee-upgrade-requests/{$first->id}/approve")->assertOk();
    $this->postJson("/api/employee-upgrade-requests/{$second->id}/approve")->assertUnprocessable();
    Sanctum::actingAs($this->hr);
    $this->postJson("/api/employee-upgrade-requests/{$first->id}/cancel")->assertForbidden();
    Sanctum::actingAs($this->ceo);
    $this->postJson("/api/employee-upgrade-requests/{$first->id}/cancel")->assertOk();
    $this->postJson("/api/employee-upgrade-requests/{$second->id}/approve")->assertOk();
    $this->postJson("/api/employee-upgrade-requests/{$second->id}/cancel")->assertOk();
    $this->travelTo(Carbon::parse('2026-11-02', 'Asia/Phnom_Penh'));
    $this->artisan('employees:apply-due-promotions')->assertSuccessful();
    expect($this->employee->fresh()->base_salary)->toBe('400.00');
});

test('overdue promotion applies after downtime and an applied promotion cannot be cancelled', function () {
    $request = effectiveRequest($this->employee, $this->hr);
    app(EmployeeUpgradeRequestService::class)->approve($request, $this->ceo);
    $this->travelTo(Carbon::parse('2026-12-03', 'Asia/Phnom_Penh'));
    $this->artisan('employees:apply-due-promotions')->assertSuccessful();
    Sanctum::actingAs($this->ceo);
    $this->postJson("/api/employee-upgrade-requests/{$request->id}/cancel")->assertForbidden();
    expect(EmploymentHistory::query()->sole()->effective_date->toDateString())->toBe('2026-11-01');
});

test('payroll before 1AM applies due promotions but selects salary for each payroll month', function () {
    $request = effectiveRequest($this->employee, $this->hr);
    app(EmployeeUpgradeRequestService::class)->approve($request, $this->ceo);
    $this->travelTo(Carbon::parse('2026-11-01 00:15:00', 'Asia/Phnom_Penh'));
    $service = app(PayrollService::class);
    foreach ([9 => '400.00', 10 => '400.00', 11 => '500.00'] as $month => $expected) {
        $batch = $service->generate($month, 2026, $this->hr);
        expect($batch->items()->sole()->base_salary)->toBe($expected);
    }
    expect($this->employee->fresh()->base_salary)->toBe('500.00')
        ->and(EmploymentHistory::query()->count())->toBe(1);
});

test('salary resolver handles multiple months and final same month correction', function () {
    foreach ([['2026-05-01', 400, 450], ['2026-11-01', 450, 500], ['2026-11-01', 500, 550]] as [$date, $old, $new]) {
        EmploymentHistory::query()->create([
            'employee_id' => $this->employee->id, 'field' => 'base_salary', 'old_value' => ['value' => $old],
            'new_value' => ['value' => $new], 'effective_date' => $date, 'source' => 'promotion',
        ]);
    }
    $service = app(SalaryHistoryService::class);
    $histories = $service->historiesFor([$this->employee->id])->get($this->employee->id);
    foreach (['2026-04-01' => 400.0, '2026-10-01' => 450.0, '2026-11-01' => 550.0] as $date => $expected) {
        expect($service->resolve($this->employee, Carbon::parse($date), $histories))->toBe($expected);
    }
});

test('unverified historical salary blocks payroll instead of guessing', function () {
    $this->employee->update(['salary_history_start_month' => '2026-10-01']);
    expect(fn () => app(PayrollService::class)->generate(9, 2026, $this->hr))
        ->toThrow(ApiException::class, 'Salary history is not verified');
    expect(PayrollBatch::query()->count())->toBe(0);
});

test('legacy midmonth salary changes block the affected payroll month', function () {
    EmploymentHistory::query()->create([
        'employee_id' => $this->employee->id, 'field' => 'base_salary',
        'old_value' => ['value' => 300], 'new_value' => ['value' => 400], 'effective_date' => '2026-09-15',
    ]);
    expect(fn () => app(PayrollService::class)->generate(9, 2026, $this->hr))
        ->toThrow(ApiException::class, 'legacy mid-month');
});

test('direct salary correction records current month history without promotion approval', function () {
    Sanctum::actingAs($this->hr);
    $this->putJson("/api/employees/{$this->employee->id}", correctSalaryPayload($this->employee, 425))->assertOk();
    $history = EmploymentHistory::query()->sole();
    expect($history->source)->toBe('correction')->and($history->effective_date->toDateString())->toBe('2026-10-01')
        ->and(EmployeeUpgradeRequest::query()->count())->toBe(0);
    expect(app(PayrollService::class)->generate(9, 2026, $this->hr)->items()->sole()->base_salary)->toBe('400.00');
    expect(app(PayrollService::class)->generate(10, 2026, $this->hr)->items()->sole()->base_salary)->toBe('425.00');
});

test('existing payroll blocks current month corrections and leaves saved payroll unchanged', function () {
    $batch = app(PayrollService::class)->generate(10, 2026, $this->hr);
    Sanctum::actingAs($this->hr);
    $this->putJson("/api/employees/{$this->employee->id}", correctSalaryPayload($this->employee, 425))
        ->assertUnprocessable()->assertJsonPath('message', 'Salary change blocked by existing payroll: 2026-10. Delete an editable draft first; approved payroll stays locked.');
    expect($batch->items()->sole()->base_salary)->toBe('400.00')
        ->and($this->employee->fresh()->base_salary)->toBe('400.00')
        ->and(EmploymentHistory::query()->count())->toBe(0);
});

test('earlier payroll does not block next month approval but conflicting payroll does', function () {
    app(PayrollService::class)->generate(10, 2026, $this->hr);
    $request = effectiveRequest($this->employee, $this->hr);
    app(EmployeeUpgradeRequestService::class)->approve($request, $this->ceo);
    expect($request->fresh()->status)->toBe('approved');
    // Simulate a legacy future payroll already present before these restrictions.
    PayrollBatch::query()->where('month', 10)->update(['month' => 11]);
    $request->refresh()->update(['status' => 'pending']);
    expect(fn () => app(EmployeeUpgradeRequestService::class)->approve($request, $this->ceo))
        ->toThrow(ApiException::class, 'existing payroll: 2026-11');
});

test('failed application changes nothing, remains visible, and blocks payroll generation', function () {
    $department = Department::query()->create(['name' => 'New', 'status' => 'active']);
    $position = Position::query()->create(['name' => 'New', 'department_id' => $department->id, 'status' => 'active']);
    $request = effectiveRequest($this->employee, $this->hr, ['department_id' => $department->id, 'position_id' => $position->id, 'base_salary' => 500]);
    app(EmployeeUpgradeRequestService::class)->approve($request, $this->ceo);
    $position->delete();
    $this->travelTo(Carbon::parse('2026-11-01 01:00:00', 'Asia/Phnom_Penh'));
    $this->artisan('employees:apply-due-promotions')->assertFailed();
    expect($this->employee->fresh()->base_salary)->toBe('400.00')
        ->and($this->employee->fresh()->department_id)->toBeNull()
        ->and(EmploymentHistory::query()->count())->toBe(0)
        ->and($request->fresh()->application_error)->not->toBeNull()
        ->and($request->fresh()->applied_at)->toBeNull();
    expect(fn () => app(PayrollService::class)->generate(10, 2026, $this->hr))->toThrow(ApiException::class, 'could not apply');
    expect(PayrollBatch::query()->count())->toBe(0);
});

test('future payroll generation is rejected', function () {
    expect(fn () => app(PayrollService::class)->generate(11, 2026, $this->hr))
        ->toThrow(ApiException::class, 'future month');
});

test('promotion schedule runs daily at 1AM Cambodia time', function () {
    $event = collect(Schedule::events())->first(fn ($event) => str_contains($event->command ?? '', 'employees:apply-due-promotions'));
    expect($event)->not->toBeNull()->and($event->expression)->toBe('0 1 * * *')
        ->and($event->timezone)->toBe('Asia/Phnom_Penh')->and($event->withoutOverlapping)->toBeTrue();
});

test('migration marks legacy approvals applied without replay and establishes conservative coverage', function () {
    $request = effectiveRequest($this->employee, $this->hr);
    $request->update(['status' => 'approved', 'reviewed_by' => $this->ceo->id, 'reviewed_at' => now()]);
    $migration = require database_path('migrations/2026_10_15_000001_add_promotion_application_and_salary_coverage.php');
    $migration->down();
    $migration->up();
    expect($request->fresh()->applied_at)->not->toBeNull()
        ->and($this->employee->fresh()->salary_history_start_month->toDateString())->toBe('2026-10-01')
        ->and($this->employee->fresh()->salary_history_baseline)->toBe('400.00')
        ->and(EmploymentHistory::query()->count())->toBe(0);
    $this->travelTo(Carbon::parse('2026-11-01 01:00:00', 'Asia/Phnom_Penh'));
    $this->artisan('employees:apply-due-promotions')->assertSuccessful();
    expect($this->employee->fresh()->base_salary)->toBe('400.00');
});

test('rollout baseline supersedes incomplete legacy history including future dated records', function () {
    EmploymentHistory::query()->create([
        'employee_id' => $this->employee->id, 'field' => 'base_salary',
        'old_value' => ['value' => 200], 'new_value' => ['value' => 300], 'effective_date' => '2026-11-01',
    ]);
    $migration = require database_path('migrations/2026_10_15_000001_add_promotion_application_and_salary_coverage.php');
    $migration->down();
    $migration->up();
    $employee = $this->employee->fresh();
    $resolver = app(SalaryHistoryService::class);
    $histories = $resolver->historiesFor([$employee->id])->get($employee->id);
    expect($resolver->resolve($employee, Carbon::parse('2026-10-01'), $histories))->toBe(400.0)
        ->and($resolver->resolve($employee, Carbon::parse('2026-11-01'), $histories))->toBe(400.0)
        ->and(EmploymentHistory::query()->count())->toBe(1);
});

test('a direct correction preserves the approved promotion target and actual old salary', function () {
    $request = effectiveRequest($this->employee, $this->hr);
    app(EmployeeUpgradeRequestService::class)->approve($request, $this->ceo);
    Sanctum::actingAs($this->hr);
    $this->putJson("/api/employees/{$this->employee->id}", correctSalaryPayload($this->employee, 425))->assertOk();
    $this->travelTo(Carbon::parse('2026-11-01 01:00:00', 'Asia/Phnom_Penh'));
    $this->artisan('employees:apply-due-promotions')->assertSuccessful();
    $history = EmploymentHistory::query()->where('source', 'promotion')->sole();
    expect($this->employee->fresh()->base_salary)->toBe('500.00')
        ->and($history->old_value['value'])->toBe(425.0)
        ->and($history->new_value['value'])->toBe(500.0);
});

test('application rechecks existing payroll and continues applying other employees', function () {
    $request = effectiveRequest($this->employee, $this->hr);
    app(EmployeeUpgradeRequestService::class)->approve($request, $this->ceo);
    // A legacy future payroll appears after approval.
    app(PayrollService::class)->generate(10, 2026, $this->hr)->update(['month' => 11]);
    $otherUser = User::factory()->create(['status' => 'active']);
    $other = Employee::query()->create([
        'user_id' => $otherUser->id, 'employee_id' => 'EMP-OTHER', 'full_name' => 'Other',
        'join_date' => '2026-01-01', 'base_salary' => 400, 'employment_status' => 'full-time',
    ]);
    $otherRequest = effectiveRequest($other, $this->hr);
    app(EmployeeUpgradeRequestService::class)->approve($otherRequest, $this->ceo);
    $this->travelTo(Carbon::parse('2026-11-01 01:00:00', 'Asia/Phnom_Penh'));
    $this->artisan('employees:apply-due-promotions')->assertFailed();
    expect($this->employee->fresh()->base_salary)->toBe('400.00')
        ->and($request->fresh()->application_error)->toContain('existing payroll: 2026-11')
        ->and($other->fresh()->base_salary)->toBe('500.00')
        ->and($otherRequest->fresh()->applied_at)->not->toBeNull();
});

test('account access changes only when the approved status change is applied', function () {
    $request = effectiveRequest($this->employee, $this->hr, ['employment_status' => 'terminated', 'last_working_date' => '2026-11-01']);
    $token = $this->employee->user->createToken('existing-device');
    app(EmployeeUpgradeRequestService::class)->approve($request, $this->ceo);
    expect($this->employee->fresh()->user->status->value)->toBe('active')
        ->and($this->employee->user->tokens()->count())->toBe(1);
    $this->travelTo(Carbon::parse('2026-11-01 01:00:00', 'Asia/Phnom_Penh'));
    $this->artisan('employees:apply-due-promotions')->assertSuccessful();
    expect($this->employee->fresh()->user->status->value)->toBe('inactive')
        ->and($this->employee->user->tokens()->count())->toBe(0);
});

test('transient application failures roll back every change and can retry successfully', function () {
    $request = effectiveRequest($this->employee, $this->hr);
    app(EmployeeUpgradeRequestService::class)->approve($request, $this->ceo);
    $this->travelTo(Carbon::parse('2026-11-01 01:00:00', 'Asia/Phnom_Penh'));
    $this->mock(AuditLogService::class)->shouldReceive('log')->andThrow(new RuntimeException('Transient storage failure'));
    $this->artisan('employees:apply-due-promotions')->assertFailed();
    expect($this->employee->fresh()->base_salary)->toBe('400.00')
        ->and(EmploymentHistory::query()->count())->toBe(0)
        ->and($request->fresh()->applied_at)->toBeNull()
        ->and($request->fresh()->application_error)->not->toContain('Transient storage failure');
    $this->app->instance(AuditLogService::class, new AuditLogService);
    $this->artisan('employees:apply-due-promotions')->assertSuccessful();
    expect($this->employee->fresh()->base_salary)->toBe('500.00')
        ->and(EmploymentHistory::query()->count())->toBe(1)
        ->and($request->fresh()->application_error)->toBeNull();
});

test('deleted employees fail application clearly and CEO can cancel their approvals', function () {
    $request = effectiveRequest($this->employee, $this->hr);
    app(EmployeeUpgradeRequestService::class)->approve($request, $this->ceo);
    $this->employee->delete();
    $this->travelTo(Carbon::parse('2026-11-01 01:00:00', 'Asia/Phnom_Penh'));
    $this->artisan('employees:apply-due-promotions')->assertFailed();
    expect($request->fresh()->application_error)->toContain('employee was deleted');
    Sanctum::actingAs($this->ceo);
    $this->postJson("/api/employee-upgrade-requests/{$request->id}/cancel")->assertOk();
});

test('overdue promotions cannot overwrite salary corrections from a later month', function () {
    $request = effectiveRequest($this->employee, $this->hr);
    app(EmployeeUpgradeRequestService::class)->approve($request, $this->ceo);
    $this->travelTo(Carbon::parse('2026-12-01', 'Asia/Phnom_Penh'));
    Sanctum::actingAs($this->hr);
    $this->putJson("/api/employees/{$this->employee->id}", correctSalaryPayload($this->employee, 425))->assertOk();
    $this->artisan('employees:apply-due-promotions')->assertFailed();
    expect($request->fresh()->application_error)->toContain('newer salary change')
        ->and($this->employee->fresh()->base_salary)->toBe('425.00');
});
