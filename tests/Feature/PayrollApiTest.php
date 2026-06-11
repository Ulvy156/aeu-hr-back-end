<?php

use App\Models\Attendance;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PayrollBatch;
use App\Models\PayrollItem;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Carbon::setTestNow('2026-05-05 09:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function payrollCompanySettings(array $overrides = []): CompanySetting
{
    return CompanySetting::query()->create(array_merge([
        'company_name' => 'Payroll Test Company',
        'working_start_time' => '08:00:00',
        'working_end_time' => '17:00:00',
        'working_days' => [
            'monday',
            'tuesday',
            'wednesday',
            'thursday',
            'friday',
            'saturday',
            'sunday',
        ],
        'salary_currency' => 'USD',
        'payroll_day_rate' => 30,
    ], $overrides));
}

function payrollEmployeeUser(string $role = 'employee', array $userOverrides = [], array $employeeOverrides = []): array
{
    $user = User::factory()->create(array_merge([
        'status' => 'active',
    ], $userOverrides));
    $user->assignRole($role);

    $employee = Employee::query()->create(array_merge([
        'user_id' => $user->id,
        'employee_id' => 'EMP-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
        'full_name' => $user->name,
        'join_date' => '2026-01-01',
        'base_salary' => 3000,
        'employment_status' => 'active',
    ], $employeeOverrides));

    return [$user, $employee];
}

function payrollManagerUser(string $role, array $overrides = []): User
{
    $user = User::factory()->create(array_merge([
        'status' => 'active',
    ], $overrides));
    $user->assignRole($role);

    return $user;
}

function makeAttendance(Employee $employee, string $date, string $status = 'present'): Attendance
{
    return Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => $date,
        'clock_in_time' => "{$date} 08:00:00",
        'clock_out_time' => "{$date} 17:00:00",
        'status' => $status,
        'is_late' => $status === 'late',
    ]);
}

function makeApprovedLeave(Employee $employee, array $overrides = []): LeaveRequest
{
    return LeaveRequest::query()->create(array_merge([
        'employee_id' => $employee->id,
        'leave_type' => 'annual',
        'start_date' => '2026-04-01',
        'end_date' => '2026-04-01',
        'duration_type' => 'full_day',
        'total_days' => 1,
        'reason' => 'Approved leave',
        'status' => 'approved',
        'hr_approval_status' => 'approved',
        'ceo_approval_status' => 'approved',
        'hr_approved_at' => now(),
        'ceo_approved_at' => now(),
    ], $overrides));
}

test('hr can generate payroll with proration deductions and configured tax brackets', function () {
    payrollCompanySettings();

    $hr = payrollManagerUser('hr', [
        'email' => 'hr@example.com',
    ]);
    Sanctum::actingAs($hr);

    [, $fullEmployee] = payrollEmployeeUser('employee', [
        'email' => 'full.employee@example.com',
    ], [
        'employee_id' => 'EMP-10001',
        'full_name' => 'Full Employee',
    ]);
    [, $joinedMidMonthEmployee] = payrollEmployeeUser('employee', [
        'email' => 'joined.mid@example.com',
    ], [
        'employee_id' => 'EMP-10002',
        'full_name' => 'Joined Mid Month',
        'join_date' => '2026-04-16',
    ]);

    foreach (range(1, 28) as $day) {
        makeAttendance($fullEmployee, sprintf('2026-04-%02d', $day));
    }

    makeApprovedLeave($fullEmployee, [
        'start_date' => '2026-04-29',
        'end_date' => '2026-04-29',
        'leave_type' => 'annual',
    ]);
    makeApprovedLeave($fullEmployee, [
        'start_date' => '2026-04-30',
        'end_date' => '2026-04-30',
        'leave_type' => 'unpaid',
    ]);

    foreach (range(16, 29) as $day) {
        makeAttendance($joinedMidMonthEmployee, sprintf('2026-04-%02d', $day));
    }

    $this->postJson('/api/payrolls', [
        'month' => 4,
        'year' => 2026,
    ])
        ->assertCreated()
        ->assertJsonPath('message', 'Payroll generated successfully.')
        ->assertJsonPath('data.month', 4)
        ->assertJsonPath('data.year', 2026)
        ->assertJsonPath('data.item_count', 2);

    $batch = PayrollBatch::query()->with(['items.employee'])->sole();

    $fullEmployeeItem = $batch->items->firstWhere('employee_id', $fullEmployee->id);
    $joinedMidMonthItem = $batch->items->firstWhere('employee_id', $joinedMidMonthEmployee->id);

    expect($fullEmployeeItem)->not->toBeNull()
        ->and($fullEmployeeItem->working_days)->toBe('30.00')
        ->and($fullEmployeeItem->present_days)->toBe('28.00')
        ->and($fullEmployeeItem->unpaid_leave_days)->toBe('1.00')
        ->and($fullEmployeeItem->absent_days)->toBe('0.00')
        ->and($fullEmployeeItem->gross_salary)->toBe('3000.00')
        ->and($fullEmployeeItem->unpaid_deduction)->toBe('100.00')
        ->and($fullEmployeeItem->tax_rate)->toBe('0.0983')
        ->and($fullEmployeeItem->tax_amount)->toBe('285.00')
        ->and($fullEmployeeItem->nssf_deduction)->toBe('6.00')
        ->and($fullEmployeeItem->net_salary)->toBe('2609.00')
        ->and(data_get($fullEmployeeItem->tax_breakdown, '0.tax_amount'))->toBe('0.00')
        ->and(data_get($fullEmployeeItem->tax_breakdown, '1.tax_amount'))->toBe('6.25')
        ->and(data_get($fullEmployeeItem->tax_breakdown, '3.tax_amount'))->toBe('116.25');

    expect($joinedMidMonthItem)->not->toBeNull()
        ->and($joinedMidMonthItem->working_days)->toBe('15.00')
        ->and($joinedMidMonthItem->present_days)->toBe('14.00')
        ->and($joinedMidMonthItem->absent_days)->toBe('1.00')
        ->and($joinedMidMonthItem->gross_salary)->toBe('1500.00')
        ->and($joinedMidMonthItem->absence_deduction)->toBe('100.00')
        ->and($joinedMidMonthItem->tax_rate)->toBe('0.0688')
        ->and($joinedMidMonthItem->tax_amount)->toBe('96.25')
        ->and($joinedMidMonthItem->nssf_deduction)->toBe('6.00')
        ->and($joinedMidMonthItem->net_salary)->toBe('1297.75');

    expect(Activity::query()->where('log_name', 'payrolls')->where('description', 'generate')->exists())->toBeTrue();

    $this->postJson('/api/payrolls', [
        'month' => 4,
        'year' => 2026,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Payroll has already been generated for the selected month and year.');

    expect(fn () => PayrollBatch::query()->create([
        'month' => 4,
        'year' => 2026,
        'status' => 'draft',
    ]))->toThrow(QueryException::class);
});

test('hr can review submit and ceo can approve payroll while approved batches remain locked', function () {
    payrollCompanySettings();

    $hr = payrollManagerUser('hr', [
        'email' => 'hr.review@example.com',
    ]);
    $ceo = payrollManagerUser('ceo', [
        'email' => 'ceo.review@example.com',
    ]);

    expect($hr->hasPermissionTo('payrolls.submit'))->toBeTrue()
        ->and($ceo->hasPermissionTo('payrolls.approve'))->toBeTrue();

    [, $employee] = payrollEmployeeUser('employee', [
        'email' => 'editable.employee@example.com',
    ], [
        'employee_id' => 'EMP-20001',
        'full_name' => 'Editable Employee',
    ]);

    foreach (range(1, 30) as $day) {
        makeAttendance($employee, sprintf('2026-04-%02d', $day));
    }

    Sanctum::actingAs($hr);

    $batchId = $this->postJson('/api/payrolls', [
        'month' => 4,
        'year' => 2026,
    ])
        ->assertCreated()
        ->json('data.id');

    $itemId = PayrollItem::query()->where('employee_id', $employee->id)->value('id');

    $this->putJson("/api/payrolls/{$batchId}", [
        'items' => [
            [
                'id' => $itemId,
                'unpaid_leave_days' => 2,
                'tax_amount' => 50,
            ],
        ],
    ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Payroll updated successfully.')
        ->assertJsonPath('data.status', 'draft');

    $updatedItem = PayrollItem::query()->findOrFail($itemId);

    expect($updatedItem->unpaid_leave_days)->toBe('2.00')
        ->and($updatedItem->unpaid_deduction)->toBe('200.00')
        ->and($updatedItem->tax_rate)->toBe('0.0964')
        ->and($updatedItem->tax_amount)->toBe('270.00')
        ->and($updatedItem->nssf_deduction)->toBe('6.00')
        ->and($updatedItem->net_salary)->toBe('2524.00');

    $this->postJson("/api/payrolls/{$batchId}/submit")
        ->assertSuccessful()
        ->assertJsonPath('message', 'Payroll submitted successfully.')
        ->assertJsonPath('data.status', 'pending_approval');

    Sanctum::actingAs($ceo);

    $this->postJson("/api/payrolls/{$batchId}/approve")
        ->assertSuccessful()
        ->assertJsonPath('message', 'Payroll approved successfully.')
        ->assertJsonPath('data.status', 'approved');

    expect(PayrollItem::query()->findOrFail($itemId)->status)->toBe('locked');

    Sanctum::actingAs($hr);

    $this->getJson('/api/payrolls?month=4&year=2026&status=approved')
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1);

    $this->getJson("/api/payrolls/{$batchId}")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $batchId);

    $this->putJson("/api/payrolls/{$batchId}", [
        'items' => [
            [
                'id' => $itemId,
                'unpaid_leave_days' => 1,
            ],
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Approved payroll batches cannot be edited.');
});

test('rejection requires a reason and rejected payroll can be revised back to draft', function () {
    payrollCompanySettings();

    $hr = payrollManagerUser('hr', [
        'email' => 'hr.reject@example.com',
    ]);
    $ceo = payrollManagerUser('ceo', [
        'email' => 'ceo.reject@example.com',
    ]);

    expect($ceo->hasPermissionTo('payrolls.reject'))->toBeTrue();

    [, $employee] = payrollEmployeeUser('employee', [
        'email' => 'reject.employee@example.com',
    ], [
        'employee_id' => 'EMP-30001',
    ]);

    foreach (range(1, 30) as $day) {
        makeAttendance($employee, sprintf('2026-04-%02d', $day));
    }

    Sanctum::actingAs($hr);

    $batchId = $this->postJson('/api/payrolls', [
        'month' => 4,
        'year' => 2026,
    ])
        ->assertCreated()
        ->json('data.id');

    $itemId = PayrollItem::query()->where('employee_id', $employee->id)->value('id');

    $this->postJson("/api/payrolls/{$batchId}/submit")
        ->assertSuccessful();

    Sanctum::actingAs($ceo);

    $this->postJson("/api/payrolls/{$batchId}/reject", [])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors('rejection_reason');

    $this->postJson("/api/payrolls/{$batchId}/reject", [
        'rejection_reason' => 'Please correct the manual adjustments.',
    ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Payroll rejected successfully.')
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.rejection_reason', 'Please correct the manual adjustments.');

    Sanctum::actingAs($hr);

    $this->putJson("/api/payrolls/{$batchId}", [
        'items' => [
            [
                'id' => $itemId,
                'absent_days' => 1,
            ],
        ],
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.rejection_reason', null);

    expect(PayrollItem::query()->findOrFail($itemId)->absent_days)->toBe('1.00')
        ->and(Activity::query()->where('log_name', 'payrolls')->where('description', 'reject')->exists())->toBeTrue()
        ->and(Activity::query()->where('log_name', 'payrolls')->where('description', 'edit')->exists())->toBeTrue();

    $activity = Activity::query()
        ->where('log_name', 'payrolls')
        ->where('description', 'edit')
        ->latest('id')
        ->first();

    expect(data_get($activity?->properties->toArray(), 'old_values.items'))->toBeNull()
        ->and(data_get($activity?->properties->toArray(), 'new_values.items'))->toBeNull();
});

test('tax snapshot remains stable after config changes and payslip reads stored values', function () {
    payrollCompanySettings();

    $hr = payrollManagerUser('hr', [
        'email' => 'hr.snapshot@example.com',
    ]);
    $ceo = payrollManagerUser('ceo', [
        'email' => 'ceo.snapshot@example.com',
    ]);
    [$employeeUser, $employee] = payrollEmployeeUser('employee', [
        'email' => 'employee.snapshot@example.com',
    ], [
        'employee_id' => 'EMP-35001',
    ]);

    foreach (range(1, 30) as $day) {
        makeAttendance($employee, sprintf('2026-04-%02d', $day));
    }

    Sanctum::actingAs($hr);

    $batchId = $this->postJson('/api/payrolls', [
        'month' => 4,
        'year' => 2026,
    ])
        ->assertCreated()
        ->json('data.id');

    $this->postJson("/api/payrolls/{$batchId}/submit")->assertSuccessful();

    Sanctum::actingAs($ceo);
    $this->postJson("/api/payrolls/{$batchId}/approve")->assertSuccessful();

    $item = PayrollItem::query()
        ->where('payroll_batch_id', $batchId)
        ->where('employee_id', $employee->id)
        ->sole();

    expect($item->tax_rate)->toBe('0.1000')
        ->and($item->tax_amount)->toBe('300.00')
        ->and($item->nssf_deduction)->toBe('6.00')
        ->and(data_get($item->tax_breakdown, '1.tax_amount'))->toBe('6.25');

    config()->set('hr.payroll.tax_brackets', [
        ['up_to' => null, 'rate' => 0.50],
    ]);

    Sanctum::actingAs($employeeUser);

    $this->getJson("/api/payslips/{$item->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.tax_amount', '300.00')
        ->assertJsonPath('data.nssf_deduction', '6.00')
        ->assertJsonPath('data.tax_breakdown.1.tax_amount', '6.25');

    expect($item->fresh()->tax_rate)->toBe('0.1000')
        ->and($item->fresh()->tax_amount)->toBe('300.00')
        ->and($item->fresh()->nssf_deduction)->toBe('6.00');
});

test('employees can view and download only their own approved payslips while managers can inspect any status', function () {
    Storage::fake(config('filesystems.cloud'));

    Storage::disk(config('filesystems.cloud'))->put(
        'company-logos/test-logo.png',
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9WnXnX8AAAAASUVORK5CYII=')
    );

    payrollCompanySettings([
        'company_logo' => 'company-logos/test-logo.png',
    ]);

    $hr = payrollManagerUser('hr', [
        'email' => 'hr.payslip@example.com',
    ]);
    $ceo = payrollManagerUser('ceo', [
        'email' => 'ceo.payslip@example.com',
    ]);
    $admin = payrollManagerUser('admin', [
        'email' => 'admin.payslip@example.com',
    ]);

    [$employeeUser, $employee] = payrollEmployeeUser('employee', [
        'email' => 'employee.payslip@example.com',
    ], [
        'employee_id' => 'EMP-40001',
        'full_name' => 'Payslip Owner',
    ]);
    [, $otherEmployee] = payrollEmployeeUser('employee', [
        'email' => 'other.payslip@example.com',
    ], [
        'employee_id' => 'EMP-40002',
        'full_name' => 'Other Payslip Owner',
    ]);

    foreach (range(1, 30) as $day) {
        makeAttendance($employee, sprintf('2026-04-%02d', $day));
        makeAttendance($otherEmployee, sprintf('2026-04-%02d', $day));
        makeAttendance($employee, sprintf('2026-05-%02d', min($day, 31)));
    }

    Sanctum::actingAs($hr);

    $approvedBatchId = $this->postJson('/api/payrolls', [
        'month' => 4,
        'year' => 2026,
    ])
        ->assertCreated()
        ->json('data.id');

    $this->postJson("/api/payrolls/{$approvedBatchId}/submit")
        ->assertSuccessful();

    expect($ceo->hasPermissionTo('payrolls.approve'))->toBeTrue()
        ->and($employeeUser->hasPermissionTo('payslips.view_own'))->toBeTrue()
        ->and($employeeUser->hasPermissionTo('payslips.download_own'))->toBeTrue();

    Sanctum::actingAs($ceo);

    $this->postJson("/api/payrolls/{$approvedBatchId}/approve")
        ->assertSuccessful();

    Sanctum::actingAs($hr);

    $draftBatchId = $this->postJson('/api/payrolls', [
        'month' => 5,
        'year' => 2026,
    ])
        ->assertCreated()
        ->json('data.id');

    $approvedOwnPayslip = PayrollItem::query()
        ->where('payroll_batch_id', $approvedBatchId)
        ->where('employee_id', $employee->id)
        ->sole();
    $approvedOtherPayslip = PayrollItem::query()
        ->where('payroll_batch_id', $approvedBatchId)
        ->where('employee_id', $otherEmployee->id)
        ->sole();
    $draftOwnPayslip = PayrollItem::query()
        ->where('payroll_batch_id', $draftBatchId)
        ->where('employee_id', $employee->id)
        ->sole();

    Sanctum::actingAs($employeeUser);

    $this->getJson('/api/payslips')
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $approvedOwnPayslip->id)
        ->assertJsonPath('data.0.payroll_batch.status', 'approved');

    $this->getJson("/api/payslips/{$approvedOwnPayslip->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $approvedOwnPayslip->id)
        ->assertJsonPath('data.nssf_deduction', '6.00')
        ->assertJsonPath('data.tax_breakdown.1.tax_amount', '6.25');

    $this->getJson("/api/payslips/{$draftOwnPayslip->id}")
        ->assertForbidden();

    $this->getJson("/api/payslips/{$approvedOtherPayslip->id}")
        ->assertForbidden();

    $downloadResponse = $this->get("/api/payslips/{$approvedOwnPayslip->id}/download");

    $downloadResponse->assertOk();
    expect((string) $downloadResponse->headers->get('content-type'))->toContain('application/pdf')
        ->and((string) $downloadResponse->headers->get('content-disposition'))->toContain('attachment;');

    Sanctum::actingAs($hr);

    $this->getJson('/api/payslips?month=5&year=2026&status=draft')
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 2);

    Sanctum::actingAs($ceo);

    $this->getJson("/api/payslips/{$approvedOtherPayslip->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $approvedOtherPayslip->id);

    Sanctum::actingAs($admin);

    $this->getJson("/api/payslips/{$draftOwnPayslip->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $draftOwnPayslip->id);
});

test('maternity leave deducts 50% of daily rate and employee receives half salary', function () {
    payrollCompanySettings();

    $hr = payrollManagerUser('hr', [
        'email' => 'hr.maternity@example.com',
    ]);
    Sanctum::actingAs($hr);

    [, $employee] = payrollEmployeeUser('employee', [
        'email' => 'employee.maternity@example.com',
    ], [
        'employee_id' => 'EMP-60001',
        'full_name' => 'Maternity Employee',
        'base_salary' => 5000,
    ]);

    makeApprovedLeave($employee, [
        'leave_type' => 'maternity',
        'start_date' => '2026-04-01',
        'end_date' => '2026-04-30',
        'duration_type' => 'full_day',
        'total_days' => 30,
    ]);

    $batchId = $this->postJson('/api/payrolls', [
        'month' => 4,
        'year' => 2026,
    ])
        ->assertCreated()
        ->json('data.id');

    $item = PayrollItem::query()
        ->where('payroll_batch_id', $batchId)
        ->where('employee_id', $employee->id)
        ->sole();

    expect($item->working_days)->toBe('30.00')
        ->and($item->present_days)->toBe('0.00')
        ->and($item->absent_days)->toBe('0.00')
        ->and($item->maternity_leave_days)->toBe('30.00')
        ->and($item->gross_salary)->toBe('5000.00')
        ->and($item->unpaid_deduction)->toBe('0.00')
        ->and($item->absence_deduction)->toBe('0.00')
        ->and($item->maternity_deduction)->toBe('2500.00')
        ->and($item->taxable_salary)->toBe('2500.00')
        ->and($item->tax_amount)->toBe('225.00')
        ->and($item->tax_rate)->toBe('0.0900')
        ->and($item->nssf_deduction)->toBe('6.00')
        ->and($item->net_salary)->toBe('2269.00');
});

test('nssf deduction uses lower threshold for low salary payroll items', function () {
    payrollCompanySettings();

    $hr = payrollManagerUser('hr', [
        'email' => 'hr.nssf@example.com',
    ]);
    Sanctum::actingAs($hr);

    [, $employee] = payrollEmployeeUser('employee', [
        'email' => 'employee.nssf@example.com',
    ], [
        'employee_id' => 'EMP-50001',
        'base_salary' => 200,
    ]);

    foreach (range(1, 30) as $day) {
        makeAttendance($employee, sprintf('2026-04-%02d', $day));
    }

    $batchId = $this->postJson('/api/payrolls', [
        'month' => 4,
        'year' => 2026,
    ])
        ->assertCreated()
        ->json('data.id');

    $item = PayrollItem::query()
        ->where('payroll_batch_id', $batchId)
        ->where('employee_id', $employee->id)
        ->sole();

    expect($item->gross_salary)->toBe('200.00')
        ->and($item->tax_amount)->toBe('0.00')
        ->and($item->nssf_deduction)->toBe('4.00')
        ->and($item->net_salary)->toBe('196.00');
});
