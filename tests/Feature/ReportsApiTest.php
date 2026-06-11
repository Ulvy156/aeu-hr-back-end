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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Carbon::setTestNow('2026-05-05 09:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function reportCompanySettings(array $overrides = []): CompanySetting
{
    return CompanySetting::query()->create(array_merge([
        'company_name' => 'Reports Test Company',
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

function reportEmployeeUser(string $role = 'employee', array $userOverrides = [], array $employeeOverrides = []): array
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

function reportManagerUser(string $role, array $overrides = []): User
{
    $user = User::factory()->create(array_merge([
        'status' => 'active',
    ], $overrides));
    $user->assignRole($role);

    return $user;
}

function reportPayrollBatch(array $overrides = []): PayrollBatch
{
    return PayrollBatch::query()->create(array_merge([
        'month' => 4,
        'year' => 2026,
        'status' => 'approved',
        'generated_at' => now()->subDay(),
        'submitted_at' => now()->subHours(12),
        'approved_at' => now(),
    ], $overrides));
}

function reportPayrollItem(PayrollBatch $batch, Employee $employee, array $overrides = []): PayrollItem
{
    return PayrollItem::query()->create(array_merge([
        'payroll_batch_id' => $batch->id,
        'employee_id' => $employee->id,
        'base_salary' => 3000,
        'daily_rate' => 100,
        'working_days' => 30,
        'present_days' => 30,
        'absent_days' => 0,
        'unpaid_leave_days' => 0,
        'gross_salary' => 3000,
        'unpaid_deduction' => 0,
        'absence_deduction' => 0,
        'taxable_salary' => 3000,
        'tax_rate' => 0.0667,
        'tax_amount' => 200,
        'net_salary' => 2800,
        'status' => 'locked',
    ], $overrides));
}

function reportAttendance(Employee $employee, string $date, string $status = 'present', array $overrides = []): Attendance
{
    return Attendance::query()->create(array_merge([
        'employee_id' => $employee->id,
        'attendance_date' => $date,
        'clock_in_time' => "{$date} 08:00:00",
        'clock_out_time' => $status === 'missing_clock_out' ? null : "{$date} 17:00:00",
        'status' => $status,
        'is_late' => $status === 'late',
    ], $overrides));
}

function reportLeave(Employee $employee, array $overrides = []): LeaveRequest
{
    return LeaveRequest::query()->create(array_merge([
        'employee_id' => $employee->id,
        'leave_type' => 'annual',
        'start_date' => '2026-05-04',
        'end_date' => '2026-05-04',
        'duration_type' => 'full_day',
        'total_days' => 1,
        'reason' => 'Reports leave request',
        'status' => 'pending',
        'hr_approval_status' => 'pending',
        'ceo_approval_status' => 'pending',
    ], $overrides));
}

test('hr can view and export payroll reports while ceo cannot export them', function () {
    reportCompanySettings();
    $hr = reportManagerUser('hr', ['email' => 'hr.report@example.com']);
    $ceo = reportManagerUser('ceo', ['email' => 'ceo.report@example.com']);
    [, $employee] = reportEmployeeUser('employee', ['email' => 'employee.report@example.com'], [
        'employee_id' => 'EMP-70001',
        'full_name' => 'Report Employee',
    ]);
    [, $otherEmployee] = reportEmployeeUser('employee', ['email' => 'other.report@example.com'], [
        'employee_id' => 'EMP-70002',
        'full_name' => 'Other Report Employee',
    ]);

    $approvedBatch = reportPayrollBatch([
        'month' => 4,
        'year' => 2026,
        'status' => 'approved',
    ]);
    $draftBatch = reportPayrollBatch([
        'month' => 5,
        'year' => 2026,
        'status' => 'draft',
        'approved_at' => null,
        'submitted_at' => null,
    ]);

    $targetItem = reportPayrollItem($approvedBatch, $employee);
    reportPayrollItem($draftBatch, $otherEmployee, [
        'net_salary' => 2500,
        'status' => 'draft',
    ]);

    Sanctum::actingAs($hr);

    $this->getJson("/api/reports/payroll?report_type=employee_list&month=4&year=2026&employee_id={$employee->id}")
        ->assertSuccessful()
        ->assertJsonPath('message', 'Payroll report fetched successfully.')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.report_type', 'employee_list')
        ->assertJsonPath('data.summary.item_count', 1)
        ->assertJsonPath('data.items.0.id', $targetItem->id)
        ->assertJsonPath('data.items.0.payroll_batch.status', 'approved');

    $exportResponse = $this->get('/api/reports/payroll/export?report_type=employee_list&month=4&year=2026');

    $exportResponse->assertOk();
    expect((string) $exportResponse->headers->get('content-disposition'))->toContain('payroll-employee-list-report.xlsx');

    Sanctum::actingAs($ceo);

    $this->get('/api/reports/payroll/export?report_type=employee_list&month=4&year=2026')
        ->assertForbidden();
});

test('attendance reports return monthly summary and correction exports while employees remain forbidden', function () {
    reportCompanySettings();
    $hr = reportManagerUser('hr', ['email' => 'hr.attendance@example.com']);
    $ceo = reportManagerUser('ceo', ['email' => 'ceo.attendance@example.com']);
    [$employeeUser, $employee] = reportEmployeeUser('employee', ['email' => 'employee.attendance@example.com'], [
        'employee_id' => 'EMP-71001',
        'full_name' => 'Attendance Employee',
    ]);
    [, $otherEmployee] = reportEmployeeUser('employee', ['email' => 'other.attendance@example.com'], [
        'employee_id' => 'EMP-71002',
        'full_name' => 'Other Attendance Employee',
    ]);

    reportAttendance($employee, '2026-05-02', 'present');
    reportAttendance($employee, '2026-05-03', 'late', [
        'corrected_at' => now(),
        'corrected_by' => $hr->id,
        'correction_reason' => 'Corrected shift',
    ]);
    reportAttendance($otherEmployee, '2026-05-04', 'absent');
    reportAttendance($otherEmployee, '2026-05-05', 'missing_clock_out');

    Sanctum::actingAs($ceo);

    $this->getJson('/api/reports/attendance?report_type=monthly_summary&month=5&year=2026')
        ->assertSuccessful()
        ->assertJsonPath('message', 'Attendance report fetched successfully.')
        ->assertJsonPath('data.report_type', 'monthly_summary')
        ->assertJsonPath('data.summary.employee_count', 2)
        ->assertJsonPath('data.summary.present_count', 1)
        ->assertJsonPath('data.summary.late_count', 1)
        ->assertJsonPath('data.summary.absent_count', 1)
        ->assertJsonPath('data.summary.missing_clock_out_count', 1)
        ->assertJsonPath('data.summary.corrected_count', 1);

    Sanctum::actingAs($hr);

    $correctionExport = $this->get('/api/reports/attendance/export?report_type=correction_list&month=5&year=2026');

    $correctionExport->assertOk();
    expect((string) $correctionExport->headers->get('content-disposition'))->toContain('attendance-correction-list-report.xlsx');

    Sanctum::actingAs($employeeUser);

    $this->getJson('/api/reports/attendance?report_type=monthly_summary&month=5&year=2026')
        ->assertForbidden();
});

test('leave reports support pending approval lists leave balance exports and permission boundaries', function () {
    reportCompanySettings();
    $hr = reportManagerUser('hr', ['email' => 'hr.leave.report@example.com']);
    $ceo = reportManagerUser('ceo', ['email' => 'ceo.leave.report@example.com']);
    [$employeeUser, $employee] = reportEmployeeUser('employee', ['email' => 'employee.leave.report@example.com'], [
        'employee_id' => 'EMP-72001',
        'full_name' => 'Leave Report Employee',
    ]);
    [, $otherEmployee] = reportEmployeeUser('employee', ['email' => 'other.leave.report@example.com'], [
        'employee_id' => 'EMP-72002',
        'full_name' => 'Other Leave Employee',
    ]);

    $pendingLeave = reportLeave($employee, [
        'status' => 'pending',
        'hr_approval_status' => 'pending',
        'ceo_approval_status' => 'pending',
    ]);
    reportLeave($employee, [
        'status' => 'approved',
        'hr_approval_status' => 'approved',
        'ceo_approval_status' => 'approved',
        'start_date' => '2026-05-01',
        'end_date' => '2026-05-02',
        'total_days' => 2,
    ]);
    reportLeave($otherEmployee, [
        'status' => 'approved',
        'hr_approval_status' => 'approved',
        'ceo_approval_status' => 'approved',
        'leave_type' => 'sick',
        'start_date' => '2026-05-03',
        'end_date' => '2026-05-03',
        'total_days' => 1,
    ]);

    Sanctum::actingAs($ceo);

    $this->getJson('/api/reports/leave?report_type=pending_approval')
        ->assertSuccessful()
        ->assertJsonPath('message', 'Leave report fetched successfully.')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.report_type', 'pending_approval')
        ->assertJsonPath('data.items.0.id', $pendingLeave->id);

    Sanctum::actingAs($hr);

    $this->getJson('/api/reports/leave?report_type=leave_balance&year=2026')
        ->assertSuccessful()
        ->assertJsonPath('data.report_type', 'leave_balance')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.items.0.year', 2026)
        ->assertJsonPath('data.items.0.annual.used', '2.00');

    $exportResponse = $this->get('/api/reports/leave/export?report_type=leave_balance&year=2026');

    $exportResponse->assertOk();
    expect((string) $exportResponse->headers->get('content-disposition'))->toContain('leave-balance-report.xlsx');

    Sanctum::actingAs($employeeUser);

    $this->getJson('/api/reports/leave?report_type=request_list')
        ->assertForbidden();
});
