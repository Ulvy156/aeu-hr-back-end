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

function dashboardCompanySettings(array $overrides = []): CompanySetting
{
    return CompanySetting::query()->create(array_merge([
        'company_name' => 'Dashboard Test Company',
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
        'payroll_day_rate' => 26,
        'allowed_radius_meters' => 30,
        'office_latitude' => 11.5564,
        'office_longitude' => 104.9282,
    ], $overrides));
}

function dashboardEmployeeUser(string $role = 'employee', array $userOverrides = [], array $employeeOverrides = []): array
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

function dashboardManagerUser(string $role, array $overrides = []): User
{
    $user = User::factory()->create(array_merge([
        'status' => 'active',
    ], $overrides));
    $user->assignRole($role);

    return $user;
}

function dashboardAttendance(Employee $employee, string $date, string $status = 'present'): Attendance
{
    return Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => $date,
        'clock_in_time' => "{$date} 08:00:00",
        'clock_out_time' => $status === 'missing_clock_out' ? null : "{$date} 17:00:00",
        'status' => $status,
        'is_late' => $status === 'late',
    ]);
}

function dashboardLeave(Employee $employee, array $overrides = []): LeaveRequest
{
    return LeaveRequest::query()->create(array_merge([
        'employee_id' => $employee->id,
        'leave_type' => 'annual',
        'start_date' => '2026-05-04',
        'end_date' => '2026-05-04',
        'duration_type' => 'full_day',
        'total_days' => 1,
        'reason' => 'Dashboard leave request',
        'status' => 'pending',
        'hr_approval_status' => 'pending',
        'ceo_approval_status' => 'pending',
    ], $overrides));
}

function dashboardPayrollBatch(array $overrides = []): PayrollBatch
{
    return PayrollBatch::query()->create(array_merge([
        'month' => 4,
        'year' => 2026,
        'status' => 'approved',
        'approved_at' => now(),
        'generated_at' => now()->subDay(),
        'submitted_at' => now()->subHours(12),
    ], $overrides));
}

function dashboardPayrollItem(PayrollBatch $batch, Employee $employee, array $overrides = []): PayrollItem
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

test('employee dashboard returns own attendance leave balance and latest approved payslip only', function () {
    dashboardCompanySettings();
    [$employeeUser, $employee] = dashboardEmployeeUser('employee', [
        'email' => 'employee.dashboard@example.com',
    ], [
        'employee_id' => 'EMP-60001',
        'full_name' => 'Dashboard Employee',
    ]);
    [, $otherEmployee] = dashboardEmployeeUser('employee', [
        'email' => 'other.dashboard@example.com',
    ], [
        'employee_id' => 'EMP-60002',
        'full_name' => 'Other Employee',
    ]);

    dashboardAttendance($employee, '2026-05-05', 'present');
    dashboardLeave($employee, [
        'status' => 'approved',
        'hr_approval_status' => 'approved',
        'ceo_approval_status' => 'approved',
        'start_date' => '2026-05-01',
        'end_date' => '2026-05-02',
        'total_days' => 2,
    ]);

    $ownApprovedBatch = dashboardPayrollBatch([
        'approved_at' => '2026-05-04 18:00:00',
    ]);
    $otherApprovedBatch = dashboardPayrollBatch([
        'month' => 3,
        'approved_at' => '2026-05-05 08:00:00',
    ]);

    $ownPayslip = dashboardPayrollItem($ownApprovedBatch, $employee);
    dashboardPayrollItem($otherApprovedBatch, $otherEmployee, [
        'net_salary' => 3100,
    ]);

    Sanctum::actingAs($employeeUser);

    $this->getJson('/api/dashboard/employee')
        ->assertSuccessful()
        ->assertJsonPath('message', 'Employee dashboard fetched successfully.')
        ->assertJsonPath('data.today_attendance.employee.id', $employee->id)
        ->assertJsonPath('data.leave_balance.employee.id', $employee->id)
        ->assertJsonPath('data.leave_balance.balances.0.used', '2.00')
        ->assertJsonPath('data.latest_approved_payslip.id', $ownPayslip->id)
        ->assertJsonPath('data.latest_approved_payslip.employee.id', $employee->id)
        ->assertJsonPath('data.latest_approved_payslip.payroll_batch.status', 'approved');
});

test('employee cannot access hr ceo or admin dashboards', function () {
    dashboardCompanySettings();
    [$employeeUser] = dashboardEmployeeUser('employee', [
        'email' => 'employee.forbidden@example.com',
    ]);

    Sanctum::actingAs($employeeUser);

    $this->getJson('/api/dashboard/hr')->assertForbidden();
    $this->getJson('/api/dashboard/ceo')->assertForbidden();
    $this->getJson('/api/dashboard/admin')->assertForbidden();
});

test('hr dashboard returns attendance summary pending hr leave requests and payroll status counts', function () {
    dashboardCompanySettings();
    $hr = dashboardManagerUser('hr', [
        'email' => 'hr.dashboard@example.com',
    ]);
    [, $presentEmployee] = dashboardEmployeeUser('employee', [
        'email' => 'present.dashboard@example.com',
    ]);
    [, $lateEmployee] = dashboardEmployeeUser('employee', [
        'email' => 'late.dashboard@example.com',
    ]);
    [, $absentEmployee] = dashboardEmployeeUser('employee', [
        'email' => 'absent.dashboard@example.com',
    ]);
    [, $missingClockOutEmployee] = dashboardEmployeeUser('employee', [
        'email' => 'missing.dashboard@example.com',
    ]);

    dashboardAttendance($presentEmployee, '2026-05-05', 'present');
    dashboardAttendance($lateEmployee, '2026-05-05', 'late');
    dashboardAttendance($absentEmployee, '2026-05-05', 'absent');
    dashboardAttendance($missingClockOutEmployee, '2026-05-05', 'missing_clock_out');

    $pendingHrLeave = dashboardLeave($presentEmployee, [
        'start_date' => '2026-05-06',
        'end_date' => '2026-05-06',
    ]);
    dashboardLeave($lateEmployee, [
        'status' => 'pending',
        'hr_approval_status' => 'approved',
        'ceo_approval_status' => 'pending',
        'hr_approved_at' => now(),
    ]);

    dashboardPayrollBatch([
        'month' => 2,
        'status' => 'draft',
        'approved_at' => null,
        'submitted_at' => null,
    ]);
    dashboardPayrollBatch([
        'month' => 4,
        'status' => 'approved',
        'approved_at' => now()->subDay(),
    ]);
    $pendingApprovalBatch = dashboardPayrollBatch([
        'month' => 5,
        'status' => 'pending_approval',
        'approved_at' => null,
        'submitted_at' => now(),
    ]);
    dashboardPayrollBatch([
        'month' => 1,
        'status' => 'rejected',
        'approved_at' => null,
        'rejected_at' => now(),
    ]);

    dashboardPayrollItem($pendingApprovalBatch, $presentEmployee, [
        'gross_salary' => 3200,
        'net_salary' => 2950,
    ]);

    Sanctum::actingAs($hr);

    $this->getJson('/api/dashboard/hr')
        ->assertSuccessful()
        ->assertJsonPath('message', 'HR dashboard fetched successfully.')
        ->assertJsonPath('data.today_attendance_summary.total_records', 4)
        ->assertJsonPath('data.today_attendance_summary.present_count', 1)
        ->assertJsonPath('data.today_attendance_summary.late_count', 1)
        ->assertJsonPath('data.today_attendance_summary.absent_count', 1)
        ->assertJsonPath('data.today_attendance_summary.missing_clock_out_count', 1)
        ->assertJsonPath('data.pending_leave_requests.total', 1)
        ->assertJsonPath('data.pending_leave_requests.items.0.id', $pendingHrLeave->id)
        ->assertJsonPath('data.payroll_status.counts.draft', 1)
        ->assertJsonPath('data.payroll_status.counts.pending_approval', 1)
        ->assertJsonPath('data.payroll_status.counts.approved', 1)
        ->assertJsonPath('data.payroll_status.counts.rejected', 1)
        ->assertJsonPath('data.payroll_status.latest_pending_approval_batch.id', $pendingApprovalBatch->id);
});

test('ceo dashboard returns pending ceo leave approvals and payroll approval summary', function () {
    dashboardCompanySettings();
    $ceo = dashboardManagerUser('ceo', [
        'email' => 'ceo.dashboard@example.com',
    ]);
    [, $employee] = dashboardEmployeeUser('employee', [
        'email' => 'ceo.employee@example.com',
    ]);

    $pendingCeoLeave = dashboardLeave($employee, [
        'status' => 'pending',
        'hr_approval_status' => 'approved',
        'ceo_approval_status' => 'pending',
        'hr_approved_at' => now(),
    ]);
    dashboardLeave($employee, [
        'status' => 'pending',
        'hr_approval_status' => 'pending',
        'ceo_approval_status' => 'approved',
        'ceo_approved_at' => now(),
    ]);

    $pendingApprovalBatch = dashboardPayrollBatch([
        'month' => 5,
        'status' => 'pending_approval',
        'approved_at' => null,
        'submitted_at' => now()->subHour(),
    ]);
    $olderPendingApprovalBatch = dashboardPayrollBatch([
        'month' => 4,
        'year' => 2025,
        'status' => 'pending_approval',
        'approved_at' => null,
        'submitted_at' => now()->subDays(2),
    ]);
    dashboardPayrollBatch([
        'month' => 3,
        'status' => 'approved',
    ]);

    dashboardPayrollItem($pendingApprovalBatch, $employee);
    dashboardPayrollItem($olderPendingApprovalBatch, $employee, [
        'net_salary' => 2600,
    ]);

    Sanctum::actingAs($ceo);

    $this->getJson('/api/dashboard/ceo')
        ->assertSuccessful()
        ->assertJsonPath('message', 'CEO dashboard fetched successfully.')
        ->assertJsonPath('data.pending_leave_approvals.total', 1)
        ->assertJsonPath('data.pending_leave_approvals.items.0.id', $pendingCeoLeave->id)
        ->assertJsonPath('data.payroll_approval_summary.pending_approval_count', 2)
        ->assertJsonPath('data.payroll_approval_summary.latest_pending_approval_batch.id', $pendingApprovalBatch->id)
        ->assertJsonPath('data.payroll_approval_summary.recent_pending_batches.1.id', $olderPendingApprovalBatch->id);
});

test('admin dashboard returns user summary and safe system settings summary', function () {
    dashboardCompanySettings([
        'company_name' => 'Admin Dashboard Company',
        'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
        'allowed_radius_meters' => 45,
    ]);
    $admin = dashboardManagerUser('admin', [
        'email' => 'admin.dashboard@example.com',
    ]);
    dashboardManagerUser('hr', [
        'email' => 'admin.hr@example.com',
    ]);
    dashboardManagerUser('ceo', [
        'email' => 'admin.ceo@example.com',
    ]);
    dashboardEmployeeUser('employee', [
        'email' => 'admin.employee@example.com',
    ]);

    Sanctum::actingAs($admin);

    $this->getJson('/api/dashboard/admin')
        ->assertSuccessful()
        ->assertJsonPath('message', 'Admin dashboard fetched successfully.')
        ->assertJsonPath('data.user_count', 4)
        ->assertJsonPath('data.user_summary.users_by_role.admin', 1)
        ->assertJsonPath('data.user_summary.users_by_role.hr', 1)
        ->assertJsonPath('data.user_summary.users_by_role.ceo', 1)
        ->assertJsonPath('data.user_summary.users_by_role.employee', 1)
        ->assertJsonPath('data.system_settings_summary.company_name', 'Admin Dashboard Company')
        ->assertJsonPath('data.system_settings_summary.allowed_radius_meters', 45)
        ->assertJsonPath('data.system_settings_summary.working_days_count', 5)
        ->assertJsonPath('data.system_settings_summary.office_location_configured', true)
        ->assertJsonMissingPath('data.system_settings_summary.company_email');
});
