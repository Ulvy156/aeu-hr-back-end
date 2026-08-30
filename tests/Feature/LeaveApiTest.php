<?php

use App\Models\Attendance;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PublicHoliday;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Carbon::setTestNow('2026-05-04 09:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function leaveCompanySettings(array $overrides = []): CompanySetting
{
    return CompanySetting::query()->create(array_merge([
        'company_name' => 'Leave Test Company',
        'working_start_time' => '08:00:00',
        'working_end_time' => '17:00:00',
        'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
        'salary_currency' => 'USD',
        'payroll_day_rate' => 26,
    ], $overrides));
}

function leaveEmployeeUser(string $role = 'employee', array $userOverrides = [], array $employeeOverrides = []): array
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
        'base_salary' => 1000,
        'employment_status' => 'full-time',
    ], $employeeOverrides));

    return [$user, $employee];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeLeave(Employee $employee, array $overrides = []): LeaveRequest
{
    return LeaveRequest::query()->create(array_merge([
        'employee_id' => $employee->id,
        'leave_type' => 'annual',
        'start_date' => '2026-05-04',
        'end_date' => '2026-05-04',
        'duration_type' => 'full_day',
        'total_days' => 1,
        'reason' => 'Annual leave request',
        'status' => 'pending',
        'hr_approval_status' => 'pending',
        'ceo_approval_status' => 'pending',
    ], $overrides));
}

test('employee can create leave and backend calculates total days', function () {
    leaveCompanySettings();
    [$user] = leaveEmployeeUser();
    $token = $user->createToken('employee-device')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/leaves', [
            'leave_type' => 'annual',
            'start_date' => '2026-05-04',
            'end_date' => '2026-05-06',
            'duration_type' => 'full_day',
            'reason' => 'Family event',
        ])
        ->assertCreated()
        ->assertJsonPath('message', 'Leave requested successfully.')
        ->assertJsonPath('data.leave_type', 'annual')
        ->assertJsonPath('data.total_days', '3.00')
        ->assertJsonPath('data.status', 'pending');

    expect(LeaveRequest::query()->sole()->total_days)->toBe('3.00');
});

test('leave request rejects frontend controlled total days and supports half day calculation', function () {
    leaveCompanySettings();
    [$user] = leaveEmployeeUser();
    $token = $user->createToken('employee-device')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/leaves', [
            'leave_type' => 'sick',
            'start_date' => '2026-05-04',
            'end_date' => '2026-05-04',
            'duration_type' => 'half_day',
            'reason' => 'Half-day clinic visit',
            'total_days' => 99,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('total_days');

    $this->withToken($token)
        ->postJson('/api/leaves', [
            'leave_type' => 'sick',
            'start_date' => '2026-05-04',
            'end_date' => '2026-05-04',
            'duration_type' => 'half_day',
            'reason' => 'Half-day clinic visit',
        ])
        ->assertCreated()
        ->assertJsonPath('data.total_days', '0.50');
});

test('past-date leave requests are currently allowed and use the same backend calculations', function () {
    leaveCompanySettings();
    [$user] = leaveEmployeeUser();
    $token = $user->createToken('employee-device')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/leaves', [
            'leave_type' => 'annual',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-01',
            'duration_type' => 'full_day',
            'reason' => 'Retroactive approved absence explanation',
        ])
        ->assertCreated()
        ->assertJsonPath('data.start_date', '2026-05-01')
        ->assertJsonPath('data.total_days', '1.00');
});

test('public holidays are excluded from leave total days', function () {
    leaveCompanySettings();
    PublicHoliday::query()->create([
        'holiday_date' => '2026-05-05',
        'name' => 'Coronation Day',
        'description' => 'Public holiday',
        'status' => 'active',
    ]);

    [$user] = leaveEmployeeUser();
    $token = $user->createToken('employee-device')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/leaves', [
            'leave_type' => 'annual',
            'start_date' => '2026-05-04',
            'end_date' => '2026-05-06',
            'duration_type' => 'full_day',
            'reason' => 'Holiday adjusted leave',
        ])
        ->assertCreated()
        ->assertJsonPath('data.total_days', '2.00');
});

test('non working days are excluded from leave total days', function () {
    leaveCompanySettings([
        'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
    ]);
    [$user] = leaveEmployeeUser();
    $token = $user->createToken('employee-device')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/leaves', [
            'leave_type' => 'annual',
            'start_date' => '2026-05-08',
            'end_date' => '2026-05-11',
            'duration_type' => 'full_day',
            'reason' => 'Weekend exclusion check',
        ])
        ->assertCreated()
        ->assertJsonPath('data.total_days', '2.00');
});

test('paid leave cannot exceed available balance', function () {
    leaveCompanySettings([
        'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
    ]);
    [$user, $employee] = leaveEmployeeUser();
    $token = $user->createToken('employee-device')->plainTextToken;

    makeLeave($employee, [
        'start_date' => '2026-05-01',
        'end_date' => '2026-05-18',
        'total_days' => 18,
        'status' => 'approved',
        'hr_approval_status' => 'approved',
        'ceo_approval_status' => 'approved',
        'hr_approved_by' => $user->id,
        'ceo_approved_by' => $user->id,
        'hr_approved_at' => now(),
        'ceo_approved_at' => now(),
    ]);

    $this->withToken($token)
        ->postJson('/api/leaves', [
            'leave_type' => 'annual',
            'start_date' => '2026-05-19',
            'end_date' => '2026-05-19',
            'duration_type' => 'full_day',
            'reason' => 'Should exceed balance',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Requested annual leave exceeds the available 2026 balance.');
});

test('unpaid leave has no balance limit', function () {
    leaveCompanySettings([
        'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
    ]);
    [$user, $employee] = leaveEmployeeUser();
    $token = $user->createToken('employee-device')->plainTextToken;

    makeLeave($employee, [
        'leave_type' => 'annual',
        'start_date' => '2026-05-01',
        'end_date' => '2026-05-18',
        'total_days' => 18,
        'status' => 'approved',
        'hr_approval_status' => 'approved',
        'ceo_approval_status' => 'approved',
    ]);

    $this->withToken($token)
        ->postJson('/api/leaves', [
            'leave_type' => 'unpaid',
            'start_date' => '2026-05-19',
            'end_date' => '2026-05-23',
            'duration_type' => 'full_day',
            'reason' => 'Long unpaid leave',
        ])
        ->assertCreated()
        ->assertJsonPath('data.leave_type', 'unpaid')
        ->assertJsonPath('data.total_days', '5.00');
});

test('employee can view only their own leave requests in list and detail endpoints', function () {
    leaveCompanySettings();
    [$employeeUser, $employee] = leaveEmployeeUser();
    [, $otherEmployee] = leaveEmployeeUser('employee', [
        'email' => 'other.employee@example.com',
    ], [
        'employee_id' => 'EMP-90001',
        'full_name' => 'Other Employee',
        'email' => 'other.employee@example.com',
    ]);

    $ownLeave = makeLeave($employee);
    $otherLeave = makeLeave($otherEmployee, [
        'reason' => 'Other leave request',
    ]);

    $token = $employeeUser->createToken('employee-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/leaves')
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $ownLeave->id)
        ->assertJsonMissingPath('data.0.employee.email')
        ->assertJsonMissingPath('data.0.employee.user');

    $this->withToken($token)
        ->getJson("/api/leaves/{$ownLeave->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $ownLeave->id)
        ->assertJsonMissingPath('data.employee.email')
        ->assertJsonMissingPath('data.employee.user');

    $this->withToken($token)
        ->getJson("/api/leaves/{$otherLeave->id}")
        ->assertForbidden();
});

test('hr ceo and admin can view leave requests based on their permissions', function () {
    leaveCompanySettings();
    [, $employee] = leaveEmployeeUser();
    [, $otherEmployee] = leaveEmployeeUser('employee', [
        'email' => 'other.viewer@example.com',
    ], [
        'employee_id' => 'EMP-90002',
        'full_name' => 'Other Viewer',
        'email' => 'other.viewer@example.com',
    ]);

    makeLeave($employee);
    $otherLeave = makeLeave($otherEmployee, [
        'leave_type' => 'sick',
    ]);

    foreach (['hr', 'ceo', 'admin'] as $role) {
        $manager = User::factory()->create([
            'email' => "{$role}@example.com",
        ]);
        $manager->assignRole($role);
        $token = $manager->createToken("{$role}-device")->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/leaves?employee_id={$otherEmployee->employee_id}&leave_type=sick")
            ->assertSuccessful()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment([
                'id' => $otherLeave->id,
                'leave_type' => 'sick',
            ]);

        $this->withToken($token)
            ->getJson("/api/leaves/{$otherLeave->id}")
            ->assertSuccessful()
            ->assertJsonPath('data.id', $otherLeave->id);
    }
});

test('leave balance role permissions match the expected access matrix', function () {
    $hrPermissions = Role::findByName('hr')->permissions->pluck('name');
    $ceoPermissions = Role::findByName('ceo')->permissions->pluck('name');
    $adminPermissions = Role::findByName('admin')->permissions->pluck('name');
    $employeePermissions = Role::findByName('employee')->permissions->pluck('name');

    expect($hrPermissions->contains('leave_balances.view_any'))->toBeTrue()
        ->and($hrPermissions->contains('leave_balances.view_own'))->toBeTrue()
        ->and($ceoPermissions->contains('leave_balances.view_any'))->toBeTrue()
        ->and($ceoPermissions->contains('leave_balances.view_own'))->toBeTrue()
        ->and($adminPermissions->contains('leave_balances.view_any'))->toBeTrue()
        ->and($adminPermissions->contains('leave_balances.view_own'))->toBeFalse()
        ->and($employeePermissions->contains('leave_balances.view_any'))->toBeFalse()
        ->and($employeePermissions->contains('leave_balances.view_own'))->toBeTrue();
});

test('leave balances are calculated dynamically from approved leave requests and managers can inspect by employee', function () {
    leaveCompanySettings();
    [$employeeUser, $employee] = leaveEmployeeUser();
    $employeeToken = $employeeUser->createToken('employee-device')->plainTextToken;

    makeLeave($employee, [
        'leave_type' => 'annual',
        'start_date' => '2026-05-04',
        'end_date' => '2026-05-05',
        'total_days' => 2,
        'status' => 'approved',
        'hr_approval_status' => 'approved',
        'ceo_approval_status' => 'approved',
    ]);

    makeLeave($employee, [
        'leave_type' => 'sick',
        'start_date' => '2026-05-06',
        'end_date' => '2026-05-06',
        'total_days' => 1,
        'status' => 'approved',
        'hr_approval_status' => 'approved',
        'ceo_approval_status' => 'approved',
    ]);

    $this->withToken($employeeToken)
        ->getJson('/api/leave-balances?year=2026')
        ->assertSuccessful()
        ->assertJsonPath('data.employee.id', $employee->id)
        ->assertJsonPath('data.year', 2026)
        ->assertJsonPath('data.balances.0.leave_type', 'annual')
        ->assertJsonPath('data.balances.0.remaining', '16.00')
        ->assertJsonPath('data.balances.1.remaining', '6.00')
        ->assertJsonPath('data.balances.3.remaining', '90.00')
        ->assertJsonPath('data.balances.4.leave_type', 'special_sick')
        ->assertJsonPath('data.balances.4.entitlement', '180.00')
        ->assertJsonPath('data.balances.5.is_unlimited', true);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $hrToken = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($hrToken)
        ->getJson("/api/leave-balances?employee_id={$employee->employee_id}&year=2026")
        ->assertSuccessful()
        ->assertJsonPath('data.employee.id', $employee->id)
        ->assertJsonPath('data.balances.0.remaining', '16.00');
});

test('hr and ceo default to their own leave balance when employee id is omitted', function (string $role) {
    leaveCompanySettings();
    [$user, $employee] = leaveEmployeeUser($role);
    $token = $user->createToken("{$role}-device")->plainTextToken;

    makeLeave($employee, [
        'leave_type' => 'annual',
        'start_date' => '2026-05-04',
        'end_date' => '2026-05-04',
        'total_days' => 1,
        'status' => 'approved',
        'hr_approval_status' => 'approved',
        'ceo_approval_status' => 'approved',
    ]);

    $this->withToken($token)
        ->getJson('/api/leave-balances?year=2026')
        ->assertSuccessful()
        ->assertJsonPath('data.employee.id', $employee->id)
        ->assertJsonPath('data.balances.0.used', '1.00')
        ->assertJsonPath('data.balances.0.remaining', '17.00');
})->with(['hr', 'ceo']);

test('admin must provide employee id to view leave balances and does not need an employee profile', function () {
    leaveCompanySettings();
    [, $employee] = leaveEmployeeUser();

    $admin = User::factory()->create([
        'status' => 'active',
        'email' => 'admin.balance@example.com',
    ]);
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/leave-balances?year=2026')
        ->assertBadRequest()
        ->assertJsonPath('message', 'The employee_id field is required for this account.');

    $this->withToken($token)
        ->getJson("/api/leave-balances?employee_id={$employee->employee_id}&year=2026")
        ->assertSuccessful()
        ->assertJsonPath('data.employee.id', $employee->id);
});

test('employee cannot request another employees leave balance', function () {
    leaveCompanySettings();
    [$user, $employee] = leaveEmployeeUser();
    [, $otherEmployee] = leaveEmployeeUser('employee', [
        'email' => 'leave.balance.other@example.com',
    ], [
        'employee_id' => 'EMP-90010',
        'full_name' => 'Leave Balance Other',
        'email' => 'leave.balance.other@example.com',
    ]);
    $token = $user->createToken('employee-device')->plainTextToken;

    $this->withToken($token)
        ->getJson("/api/leave-balances?employee_id={$otherEmployee->employee_id}&year=2026")
        ->assertForbidden();

    $this->withToken($token)
        ->getJson("/api/leave-balances?employee_id={$employee->employee_id}&year=2026")
        ->assertSuccessful()
        ->assertJsonPath('data.employee.id', $employee->id);
});

test('user without leave balance permission cannot view leave balances', function () {
    leaveCompanySettings();

    $user = User::factory()->create([
        'status' => 'active',
        'email' => 'no-balance-permission@example.com',
    ]);
    $token = $user->createToken('plain-user-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/leave-balances?year=2026')
        ->assertForbidden();
});

test('pending and cancelled leave do not reduce the leave balance', function () {
    leaveCompanySettings();
    [$employeeUser, $employee] = leaveEmployeeUser();
    $employeeToken = $employeeUser->createToken('employee-device')->plainTextToken;

    makeLeave($employee, [
        'leave_type' => 'annual',
        'start_date' => '2026-05-04',
        'end_date' => '2026-05-05',
        'total_days' => 2,
        'status' => 'approved',
        'hr_approval_status' => 'approved',
        'ceo_approval_status' => 'approved',
    ]);

    makeLeave($employee, [
        'leave_type' => 'annual',
        'start_date' => '2026-05-06',
        'end_date' => '2026-05-06',
        'total_days' => 1,
        'status' => 'pending',
    ]);

    makeLeave($employee, [
        'leave_type' => 'annual',
        'start_date' => '2026-05-07',
        'end_date' => '2026-05-07',
        'total_days' => 1,
        'status' => 'cancelled',
        'cancelled_at' => now(),
    ]);

    $this->withToken($employeeToken)
        ->getJson('/api/leave-balances?year=2026')
        ->assertSuccessful()
        ->assertJsonPath('data.balances.0.used', '2.00')
        ->assertJsonPath('data.balances.0.remaining', '16.00');
});

test('hr approval works and keeps leave pending until ceo approval exists', function () {
    leaveCompanySettings();
    [, $employee] = leaveEmployeeUser();
    $leave = makeLeave($employee);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->postJson("/api/leaves/{$leave->id}/approve")
        ->assertSuccessful()
        ->assertJsonPath('data.hr_approval_status', 'approved')
        ->assertJsonPath('data.ceo_approval_status', 'pending')
        ->assertJsonPath('data.status', 'pending');

    expect(Activity::query()->where('log_name', 'leaves')->where('description', 'approve')->count())->toBe(1);
});

test('ceo approval works and keeps leave pending until hr approval exists', function () {
    leaveCompanySettings();
    [, $employee] = leaveEmployeeUser();
    $leave = makeLeave($employee);

    $ceo = User::factory()->create();
    $ceo->assignRole('ceo');
    $token = $ceo->createToken('ceo-device')->plainTextToken;

    $this->withToken($token)
        ->postJson("/api/leaves/{$leave->id}/approve")
        ->assertSuccessful()
        ->assertJsonPath('data.ceo_approval_status', 'approved')
        ->assertJsonPath('data.hr_approval_status', 'pending')
        ->assertJsonPath('data.status', 'pending');
});

test('final leave approval requires both hr and ceo approvals and approval order does not matter', function () {
    leaveCompanySettings();
    [, $employee] = leaveEmployeeUser();
    $leave = makeLeave($employee);

    $hr = User::factory()->create(['email' => 'hr.approver@example.com']);
    $hr->assignRole('hr');
    $ceo = User::factory()->create(['email' => 'ceo.approver@example.com']);
    $ceo->assignRole('ceo');

    Sanctum::actingAs($ceo);
    $this->postJson("/api/leaves/{$leave->id}/approve")
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'pending');

    Sanctum::actingAs($hr);
    $this->postJson("/api/leaves/{$leave->id}/approve")
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.hr_approval_status', 'approved')
        ->assertJsonPath('data.ceo_approval_status', 'approved');
});

test('final approval of a retroactive leave clears stale absent attendance records for the covered dates', function () {
    leaveCompanySettings();
    [, $employee] = leaveEmployeeUser();

    $pastDate = '2026-04-27';
    $leave = makeLeave($employee, [
        'start_date' => $pastDate,
        'end_date' => $pastDate,
    ]);

    $absentAttendance = Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => $pastDate,
        'status' => 'absent',
        'is_late' => false,
    ]);

    $hr = User::factory()->create(['email' => 'hr.approver@example.com']);
    $hr->assignRole('hr');
    $ceo = User::factory()->create(['email' => 'ceo.approver@example.com']);
    $ceo->assignRole('ceo');

    Sanctum::actingAs($hr);
    $this->postJson("/api/leaves/{$leave->id}/approve")->assertSuccessful();

    // Not yet fully approved, so the stale absent record must remain untouched.
    expect(Attendance::query()->whereKey($absentAttendance->id)->exists())->toBeTrue();

    Sanctum::actingAs($ceo);
    $this->postJson("/api/leaves/{$leave->id}/approve")
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'approved');

    expect(Attendance::query()->whereKey($absentAttendance->id)->exists())->toBeFalse();
});

test('rejection requires a reason', function () {
    leaveCompanySettings();
    [, $employee] = leaveEmployeeUser();
    $leave = makeLeave($employee);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->postJson("/api/leaves/{$leave->id}/reject", [])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors('rejection_reason');
});

test('rejection by hr or ceo makes the final status rejected', function (string $role) {
    leaveCompanySettings();
    [, $employee] = leaveEmployeeUser();
    $leave = makeLeave($employee);

    $approver = User::factory()->create([
        'email' => "{$role}.rejector@example.com",
    ]);
    $approver->assignRole($role);
    $token = $approver->createToken("{$role}-device")->plainTextToken;

    $this->withToken($token)
        ->postJson("/api/leaves/{$leave->id}/reject", [
            'rejection_reason' => 'Insufficient coverage for requested dates.',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.rejection_reason', 'Insufficient coverage for requested dates.');
})->with(['hr', 'ceo']);

test('employee cannot approve or reject leave requests', function () {
    leaveCompanySettings();
    [$employeeUser, $employee] = leaveEmployeeUser();
    $leave = makeLeave($employee);
    $token = $employeeUser->createToken('employee-device')->plainTextToken;

    $this->withToken($token)
        ->postJson("/api/leaves/{$leave->id}/approve")
        ->assertForbidden();

    $this->withToken($token)
        ->postJson("/api/leaves/{$leave->id}/reject", [
            'rejection_reason' => 'Not allowed',
        ])
        ->assertForbidden();
});

test('approving an already rejected leave should fail', function () {
    leaveCompanySettings();
    [, $employee] = leaveEmployeeUser();
    $leave = makeLeave($employee, [
        'status' => 'rejected',
        'hr_approval_status' => 'rejected',
        'rejection_reason' => 'Already rejected',
    ]);

    $ceo = User::factory()->create();
    $ceo->assignRole('ceo');
    $token = $ceo->createToken('ceo-device')->plainTextToken;

    $this->withToken($token)
        ->postJson("/api/leaves/{$leave->id}/approve")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This leave request has already been rejected.');
});

test('approving an already cancelled leave should fail', function () {
    leaveCompanySettings();
    [, $employee] = leaveEmployeeUser();
    $leave = makeLeave($employee, [
        'status' => 'cancelled',
        'cancelled_at' => now(),
    ]);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->postJson("/api/leaves/{$leave->id}/approve")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Cancelled leave requests cannot be approved or rejected.');
});

test('rejecting an already approved leave should fail', function () {
    leaveCompanySettings();
    [, $employee] = leaveEmployeeUser();
    $leave = makeLeave($employee, [
        'status' => 'approved',
        'hr_approval_status' => 'approved',
        'ceo_approval_status' => 'approved',
        'hr_approved_at' => now(),
        'ceo_approved_at' => now(),
    ]);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->postJson("/api/leaves/{$leave->id}/reject", [
            'rejection_reason' => 'Too late to reject',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This leave request has already been fully approved.');
});

test('user without linked employee profile cannot create leave', function () {
    leaveCompanySettings();
    $user = User::factory()->create([
        'status' => 'active',
    ]);
    $user->assignRole('employee');
    $token = $user->createToken('employee-device')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/leaves', [
            'leave_type' => 'annual',
            'start_date' => '2026-05-04',
            'end_date' => '2026-05-04',
            'duration_type' => 'full_day',
            'reason' => 'Should fail without employee profile',
        ])
        ->assertForbidden()
        ->assertJsonPath('message', 'No employee profile is linked to this user account.');
});

test('employee can cancel their own pending leave and the action is audited', function () {
    leaveCompanySettings();
    [$employeeUser, $employee] = leaveEmployeeUser();
    $leave = makeLeave($employee);
    $token = $employeeUser->createToken('employee-device')->plainTextToken;

    $this->withToken($token)
        ->postJson("/api/leaves/{$leave->id}/cancel")
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'cancelled');

    expect($leave->fresh()->cancelled_at)->not->toBeNull()
        ->and(Activity::query()->where('log_name', 'leaves')->where('description', 'cancel')->exists())->toBeTrue();
});

test('employee cannot cancel approved or rejected leave', function (string $status, string $message) {
    leaveCompanySettings();
    [$employeeUser, $employee] = leaveEmployeeUser();
    $leave = makeLeave($employee, [
        'status' => $status,
        'hr_approval_status' => $status === 'approved' ? 'approved' : 'rejected',
        'ceo_approval_status' => $status === 'approved' ? 'approved' : 'pending',
        'rejection_reason' => $status === 'rejected' ? 'Rejected already' : null,
    ]);
    $token = $employeeUser->createToken('employee-device')->plainTextToken;

    $this->withToken($token)
        ->postJson("/api/leaves/{$leave->id}/cancel")
        ->assertUnprocessable()
        ->assertJsonPath('message', $message);
})->with([
    ['approved', 'Only pending leave requests can be cancelled.'],
    ['rejected', 'Only pending leave requests can be cancelled.'],
]);

test('employee cannot cancel another employees leave', function () {
    leaveCompanySettings();
    [$employeeUser] = leaveEmployeeUser();
    [, $otherEmployee] = leaveEmployeeUser('employee', [
        'email' => 'another.employee@example.com',
    ], [
        'employee_id' => 'EMP-90003',
        'full_name' => 'Another Employee',
        'email' => 'another.employee@example.com',
    ]);
    $leave = makeLeave($otherEmployee);
    $token = $employeeUser->createToken('employee-device')->plainTextToken;

    $this->withToken($token)
        ->postJson("/api/leaves/{$leave->id}/cancel")
        ->assertForbidden();
});

test('employee can create special leave and it appears in the leave balance with its own entitlement', function () {
    leaveCompanySettings([
        'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
    ]);
    [$user, $employee] = leaveEmployeeUser();
    $token = $user->createToken('employee-device')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/leaves', [
            'leave_type' => 'special',
            'start_date' => '2026-05-04',
            'end_date' => '2026-05-06',
            'duration_type' => 'full_day',
            'reason' => 'Marriage leave',
        ])
        ->assertCreated()
        ->assertJsonPath('data.leave_type', 'special')
        ->assertJsonPath('data.total_days', '3.00')
        ->assertJsonPath('data.status', 'pending');

    makeLeave($employee, [
        'leave_type' => 'special',
        'start_date' => '2026-05-04',
        'end_date' => '2026-05-06',
        'total_days' => 3,
        'status' => 'approved',
        'hr_approval_status' => 'approved',
        'ceo_approval_status' => 'approved',
    ]);

    $this->withToken($token)
        ->getJson('/api/leave-balances?year=2026')
        ->assertSuccessful()
        ->assertJsonPath('data.balances.2.leave_type', 'special')
        ->assertJsonPath('data.balances.2.entitlement', '7.00')
        ->assertJsonPath('data.balances.2.used', '3.00')
        ->assertJsonPath('data.balances.2.remaining', '4.00');
});

test('special leave cannot exceed the available balance', function () {
    leaveCompanySettings([
        'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
    ]);
    [$user, $employee] = leaveEmployeeUser();
    $token = $user->createToken('employee-device')->plainTextToken;

    makeLeave($employee, [
        'leave_type' => 'special',
        'start_date' => '2026-04-27',
        'end_date' => '2026-05-03',
        'total_days' => 7,
        'status' => 'approved',
        'hr_approval_status' => 'approved',
        'ceo_approval_status' => 'approved',
    ]);

    $this->withToken($token)
        ->postJson('/api/leaves', [
            'leave_type' => 'special',
            'start_date' => '2026-05-04',
            'end_date' => '2026-05-04',
            'duration_type' => 'full_day',
            'reason' => 'Should exceed special leave balance',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Requested special leave exceeds the available 2026 balance.');
});

test('maternity leave is rejected when it exceeds the 90 day entitlement for a single case', function () {
    leaveCompanySettings([
        'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
    ]);
    [$user] = leaveEmployeeUser('employee', [], ['gender' => 'female']);
    $token = $user->createToken('employee-device')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/leaves', [
            'leave_type' => 'maternity',
            'start_date' => '2026-01-01',
            'end_date' => '2026-04-01',
            'duration_type' => 'full_day',
            'reason' => 'Maternity leave',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Requested maternity leave exceeds the 90-day entitlement for a single case.');
});

test('maternity leave is rejected for male employees', function () {
    leaveCompanySettings([
        'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
    ]);
    [$user] = leaveEmployeeUser('employee', [], ['gender' => 'male']);
    $token = $user->createToken('employee-device')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/leaves', [
            'leave_type' => 'maternity',
            'start_date' => '2026-05-04',
            'end_date' => '2026-05-06',
            'duration_type' => 'full_day',
            'reason' => 'Maternity leave',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Maternity Leave is for female employee only!');
});
