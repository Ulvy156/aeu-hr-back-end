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
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const ATTENDANCE_VALID_GPS = [
    'latitude' => 11.55640000,
    'longitude' => 104.92820000,
];

const ATTENDANCE_OUTSIDE_GPS = [
    'latitude' => 11.60000000,
    'longitude' => 104.98000000,
];

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

function attendanceCompanySettings(array $overrides = []): CompanySetting
{
    return CompanySetting::query()->create(array_merge([
        'company_name' => 'Attendance Test Company',
        'office_latitude' => (string) ATTENDANCE_VALID_GPS['latitude'],
        'office_longitude' => (string) ATTENDANCE_VALID_GPS['longitude'],
        'allowed_radius_meters' => 100,
        'working_start_time' => '08:00:00',
        'working_end_time' => '17:00:00',
        'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
        'salary_currency' => 'USD',
        'payroll_day_rate' => 26,
    ], $overrides));
}

function attendanceEmployeeUser(string $role = 'employee', array $userOverrides = [], array $employeeOverrides = []): array
{
    $user = User::factory()->create(array_merge([
        'status' => 'active',
    ], $userOverrides));
    $user->assignRole($role);

    $employee = Employee::query()->create(array_merge([
        'user_id' => $user->id,
        'employee_id' => 'EMP-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
        'full_name' => $user->name,
        'join_date' => '2026-05-01',
        'base_salary' => 1000,
        'employment_status' => 'full-time',
    ], $employeeOverrides));

    return [$user, $employee];
}

test('employee can clock in within allowed radius and late status is calculated on backend', function () {
    Carbon::setTestNow('2026-05-05 08:30:00');
    attendanceCompanySettings();

    [$user] = attendanceEmployeeUser();
    $token = $user->createToken('employee-device')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/attendance/clock-in', ATTENDANCE_VALID_GPS)
        ->assertCreated()
        ->assertJsonPath('message', 'Clock-in successful.')
        ->assertJsonPath('data.status', 'late')
        ->assertJsonPath('data.is_late', true);

    $attendance = Attendance::query()->sole();

    expect(Attendance::query()->count())->toBe(1)
        ->and((float) $attendance->clock_in_latitude)->toEqualWithDelta(ATTENDANCE_VALID_GPS['latitude'], 0.00000001)
        ->and((float) $attendance->clock_in_longitude)->toEqualWithDelta(ATTENDANCE_VALID_GPS['longitude'], 0.00000001);
});

test('clock in is rejected outside the allowed radius and when office gps settings are missing', function () {
    Carbon::setTestNow('2026-05-05 08:00:00');
    attendanceCompanySettings([
        'allowed_radius_meters' => 50,
    ]);

    [$user] = attendanceEmployeeUser();
    $token = $user->createToken('employee-device')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/attendance/clock-in', ATTENDANCE_OUTSIDE_GPS)
        ->assertUnprocessable()
        ->assertJsonPath('message', 'You are outside the allowed clock-in location.');

    CompanySetting::query()->delete();
    attendanceCompanySettings([
        'office_latitude' => null,
        'office_longitude' => null,
    ]);

    $this->withToken($token)
        ->postJson('/api/attendance/clock-in', ATTENDANCE_VALID_GPS)
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Office GPS settings are not configured.');
});

test('clock in location checks use office gps from company settings', function () {
    Carbon::setTestNow('2026-05-05 08:00:00');
    $settings = attendanceCompanySettings([
        'office_latitude' => (string) ATTENDANCE_VALID_GPS['latitude'],
        'office_longitude' => (string) ATTENDANCE_VALID_GPS['longitude'],
        'allowed_radius_meters' => 25,
    ]);

    [$user] = attendanceEmployeeUser();
    $token = $user->createToken('employee-device')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/attendance/clock-in', ATTENDANCE_VALID_GPS)
        ->assertCreated();

    Carbon::setTestNow('2026-05-06 08:00:00');
    $settings->update([
        'office_latitude' => (string) ATTENDANCE_OUTSIDE_GPS['latitude'],
        'office_longitude' => (string) ATTENDANCE_OUTSIDE_GPS['longitude'],
        'allowed_radius_meters' => 25,
    ]);

    $this->withToken($token)
        ->postJson('/api/attendance/clock-in', ATTENDANCE_VALID_GPS)
        ->assertUnprocessable()
        ->assertJsonPath('message', 'You are outside the allowed clock-in location.');
});

test('duplicate clock in and duplicate clock out are prevented and clock out requires an existing clock in', function () {
    attendanceCompanySettings();
    [$user, $employee] = attendanceEmployeeUser();
    $token = $user->createToken('employee-device')->plainTextToken;

    Carbon::setTestNow('2026-05-05 07:55:00');

    $this->withToken($token)
        ->postJson('/api/attendance/clock-out', ATTENDANCE_VALID_GPS)
        ->assertUnprocessable()
        ->assertJsonPath('message', 'You must clock in before clocking out.');

    $this->withToken($token)
        ->postJson('/api/attendance/clock-in', ATTENDANCE_VALID_GPS)
        ->assertCreated();

    $this->withToken($token)
        ->postJson('/api/attendance/clock-in', ATTENDANCE_VALID_GPS)
        ->assertUnprocessable()
        ->assertJsonPath('message', 'You have already clocked in today.');

    Carbon::setTestNow('2026-05-05 17:10:00');

    $this->withToken($token)
        ->postJson('/api/attendance/clock-out', ATTENDANCE_VALID_GPS)
        ->assertSuccessful()
        ->assertJsonPath('message', 'Clock-out successful.');

    $this->withToken($token)
        ->postJson('/api/attendance/clock-out', ATTENDANCE_VALID_GPS)
        ->assertUnprocessable()
        ->assertJsonPath('message', 'You have already clocked out today.');

    $attendance = $employee->attendances()->sole();

    expect($attendance->clock_out_time)->not->toBeNull()
        ->and((float) $attendance->clock_out_latitude)->toEqualWithDelta(ATTENDANCE_VALID_GPS['latitude'], 0.00000001)
        ->and((float) $attendance->clock_out_longitude)->toEqualWithDelta(ATTENDANCE_VALID_GPS['longitude'], 0.00000001);
});

test('clock in is rejected for an employee on approved leave today', function () {
    Carbon::setTestNow('2026-05-05 08:00:00');
    attendanceCompanySettings();

    [$user, $employee] = attendanceEmployeeUser();
    $token = $user->createToken('employee-device')->plainTextToken;

    LeaveRequest::query()->create([
        'employee_id' => $employee->id,
        'leave_type' => 'annual',
        'start_date' => '2026-05-05',
        'end_date' => '2026-05-05',
        'duration_type' => 'full_day',
        'total_days' => 1,
        'reason' => 'Approved leave',
        'status' => 'approved',
    ]);

    $this->withToken($token)
        ->postJson('/api/attendance/clock-in', ATTENDANCE_VALID_GPS)
        ->assertUnprocessable()
        ->assertJsonPath('message', 'You are on approved leave today and cannot clock in.');

    expect(Attendance::query()->count())->toBe(0);
});

test('clock out is rejected for an employee on approved leave today', function () {
    Carbon::setTestNow('2026-05-05 07:55:00');
    attendanceCompanySettings();

    [$user, $employee] = attendanceEmployeeUser();
    $token = $user->createToken('employee-device')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/attendance/clock-in', ATTENDANCE_VALID_GPS)
        ->assertCreated();

    LeaveRequest::query()->create([
        'employee_id' => $employee->id,
        'leave_type' => 'sick',
        'start_date' => '2026-05-05',
        'end_date' => '2026-05-05',
        'duration_type' => 'full_day',
        'total_days' => 1,
        'reason' => 'Approved leave',
        'status' => 'approved',
    ]);

    Carbon::setTestNow('2026-05-05 17:10:00');

    $this->withToken($token)
        ->postJson('/api/attendance/clock-out', ATTENDANCE_VALID_GPS)
        ->assertUnprocessable()
        ->assertJsonPath('message', 'You are on approved leave today and cannot clock out.');

    expect($employee->attendances()->sole()->clock_out_time)->toBeNull();
});

test('proxy clock in is rejected for an employee on approved leave on the selected date', function () {
    Carbon::setTestNow('2026-05-05 12:00:00');
    attendanceCompanySettings();

    [, $employee] = attendanceEmployeeUser();

    LeaveRequest::query()->create([
        'employee_id' => $employee->id,
        'leave_type' => 'annual',
        'start_date' => '2026-05-05',
        'end_date' => '2026-05-05',
        'duration_type' => 'full_day',
        'total_days' => 1,
        'reason' => 'Approved leave',
        'status' => 'approved',
    ]);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/attendance/proxy-clock-in', [
            'employee_id' => $employee->id,
            'attendance_date' => '2026-05-05',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This employee is on approved leave on the selected date and cannot be clocked in.');

    expect(Attendance::query()->count())->toBe(0);
});

test('proxy clock out is rejected for an employee on approved leave on the selected date', function () {
    Carbon::setTestNow('2026-05-05 12:00:00');
    attendanceCompanySettings();

    [, $employee] = attendanceEmployeeUser();

    Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-05-05',
        'clock_in_time' => '2026-05-05 07:55:00',
        'status' => 'present',
        'is_late' => false,
    ]);

    LeaveRequest::query()->create([
        'employee_id' => $employee->id,
        'leave_type' => 'sick',
        'start_date' => '2026-05-05',
        'end_date' => '2026-05-05',
        'duration_type' => 'full_day',
        'total_days' => 1,
        'reason' => 'Approved leave',
        'status' => 'approved',
    ]);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/attendance/proxy-clock-out', [
            'employee_id' => $employee->id,
            'attendance_date' => '2026-05-05',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This employee is on approved leave on the selected date and cannot be clocked out.');

    expect($employee->attendances()->sole()->clock_out_time)->toBeNull();
});

test('attendance list scopes employees to their own records', function () {
    attendanceCompanySettings();

    [$employeeUser, $employee] = attendanceEmployeeUser();
    [, $otherEmployee] = attendanceEmployeeUser('employee', [
        'email' => 'other.employee@example.com',
    ], [
        'employee_id' => 'EMP-90001',
        'full_name' => 'Other Employee',
        'email' => 'other.employee@example.com',
    ]);

    Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-05-05',
        'clock_in_time' => '2026-05-05 08:15:00',
        'status' => 'late',
        'is_late' => true,
    ]);

    Attendance::query()->create([
        'employee_id' => $otherEmployee->id,
        'attendance_date' => '2026-05-05',
        'clock_in_time' => '2026-05-05 07:55:00',
        'status' => 'present',
        'is_late' => false,
    ]);

    $employeeToken = $employeeUser->createToken('employee-device')->plainTextToken;

    $this->withToken($employeeToken)
        ->getJson('/api/attendance')
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.employee.id', $employee->id);
});

test('attendance list allows hr to filter by employee and status', function () {
    attendanceCompanySettings();

    [, $employee] = attendanceEmployeeUser();
    [, $otherEmployee] = attendanceEmployeeUser('employee', [
        'email' => 'other.employee@example.com',
    ], [
        'employee_id' => 'EMP-90001',
        'full_name' => 'Other Employee',
        'email' => 'other.employee@example.com',
    ]);

    Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-05-05',
        'clock_in_time' => '2026-05-05 08:15:00',
        'status' => 'late',
        'is_late' => true,
    ]);

    Attendance::query()->create([
        'employee_id' => $otherEmployee->id,
        'attendance_date' => '2026-05-05',
        'clock_in_time' => '2026-05-05 07:55:00',
        'status' => 'present',
        'is_late' => false,
    ]);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $hrToken = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($hrToken)
        ->getJson("/api/attendance?employee_id={$otherEmployee->id}&status=present")
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.employee.id', $otherEmployee->id);
});

test('clock in and clock out return 403 when no employee profile is linked to the user account', function () {
    attendanceCompanySettings();

    $user = User::factory()->create([
        'status' => 'active',
    ]);
    $user->assignRole('employee');
    $token = $user->createToken('employee-device')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/attendance/clock-in', ATTENDANCE_VALID_GPS)
        ->assertForbidden()
        ->assertJsonPath('message', 'No employee profile is linked to this user account.');

    $this->withToken($token)
        ->postJson('/api/attendance/clock-out', ATTENDANCE_VALID_GPS)
        ->assertForbidden()
        ->assertJsonPath('message', 'No employee profile is linked to this user account.');
});

test('attendance correction allows only the supported fields and writes an audit log', function () {
    attendanceCompanySettings();

    [$employeeUser, $employee] = attendanceEmployeeUser();
    $attendance = Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-05-05',
        'clock_in_time' => '2026-05-05 07:55:00',
        'clock_out_time' => null,
        'status' => 'present',
        'is_late' => false,
    ]);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->putJson("/api/attendance/{$attendance->id}/correction", [
            'clock_in_time' => '2026-05-05 08:10:00',
            'clock_out_time' => '2026-05-05 17:05:00',
            'status' => 'late',
            'correction_reason' => 'Approved manual correction.',
        ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Attendance corrected successfully.')
        ->assertJsonPath('data.status', 'late')
        ->assertJsonPath('data.correction_reason', 'Approved manual correction.');

    $activity = Activity::query()
        ->where('log_name', 'attendance')
        ->where('description', 'correction')
        ->latest('id')
        ->first();

    expect($attendance->fresh()->corrected_by)->toBe($hr->id)
        ->and($attendance->fresh()->correction_reason)->toBe('Approved manual correction.')
        ->and($activity)->not->toBeNull();
});

test('attendance correction rejects gps fields and direct is_late input', function () {
    attendanceCompanySettings();

    [, $employee] = attendanceEmployeeUser();
    $attendance = Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-05-05',
        'clock_in_time' => '2026-05-05 07:55:00',
        'status' => 'present',
        'is_late' => false,
    ]);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->putJson("/api/attendance/{$attendance->id}/correction", [
            'clock_in_latitude' => 11.5000,
            'clock_out_longitude' => 104.9000,
            'correction_reason' => 'Should fail',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['clock_in_latitude', 'clock_out_longitude']);

    $this->withToken($token)
        ->putJson("/api/attendance/{$attendance->id}/correction", [
            'is_late' => true,
            'correction_reason' => 'Should fail too',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('is_late');
});

test('attendance correction recalculates is_late from corrected clock in time', function () {
    attendanceCompanySettings([
        'working_start_time' => '08:00:00',
    ]);

    [, $employee] = attendanceEmployeeUser();
    $attendance = Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-05-05',
        'clock_in_time' => '2026-05-05 07:50:00',
        'status' => 'present',
        'is_late' => false,
    ]);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->putJson("/api/attendance/{$attendance->id}/correction", [
            'clock_in_time' => '2026-05-05 08:25:00',
            'status' => 'present',
            'correction_reason' => 'Late after review',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.is_late', true);

    expect($attendance->fresh()->is_late)->toBeTrue();
});

test('mark absent uses today when no attendance date is provided', function () {
    Carbon::setTestNow('2026-05-04 18:00:00');
    attendanceCompanySettings([
        'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
    ]);

    attendanceEmployeeUser();
    attendanceEmployeeUser('employee', [
        'email' => 'employee.two@example.com',
    ], [
        'employee_id' => 'EMP-20002',
        'full_name' => 'Employee Two',
        'email' => 'employee.two@example.com',
    ]);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/attendance/mark-absent')
        ->assertSuccessful()
        ->assertJsonPath('data.attendance_date', '2026-05-04')
        ->assertJsonPath('data.created_count', 2);

    expect(Attendance::query()->whereDate('attendance_date', '2026-05-04')->count())->toBe(2);
});

test('mark absent supports a provided attendance date', function () {
    Carbon::setTestNow('2026-05-06 08:00:00');
    attendanceCompanySettings();

    attendanceEmployeeUser();

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/attendance/mark-absent', [
            'attendance_date' => '2026-05-05',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.attendance_date', '2026-05-05')
        ->assertJsonPath('data.created_count', 1);
});

test('mark absent rejects future dates', function () {
    Carbon::setTestNow('2026-05-05 08:00:00');
    attendanceCompanySettings();

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/attendance/mark-absent', [
            'attendance_date' => '2026-05-06',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed')
        ->assertJsonValidationErrors('attendance_date');
});

test('mark absent does not create duplicate attendance records', function () {
    Carbon::setTestNow('2026-05-05 18:00:00');
    attendanceCompanySettings();

    attendanceEmployeeUser();

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/attendance/mark-absent')
        ->assertSuccessful()
        ->assertJsonPath('data.created_count', 1);

    $this->withToken($token)
        ->postJson('/api/attendance/mark-absent')
        ->assertSuccessful()
        ->assertJsonPath('data.created_count', 0);

    expect(Attendance::query()->count())->toBe(1);
});

test('mark absent skips public holidays', function () {
    Carbon::setTestNow('2026-05-05 18:00:00');
    attendanceCompanySettings();

    attendanceEmployeeUser();

    PublicHoliday::query()->create([
        'holiday_date' => '2026-05-05',
        'name' => 'Public Holiday',
        'description' => 'Holiday',
        'status' => 'active',
    ]);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/attendance/mark-absent')
        ->assertSuccessful()
        ->assertJsonPath('data.created_count', 0);

    expect(Attendance::query()->count())->toBe(0);
});

test('mark absent skips employees with approved leave', function () {
    Carbon::setTestNow('2026-05-05 18:00:00');
    attendanceCompanySettings();

    [, $employeeOnLeave] = attendanceEmployeeUser();
    [, $availableEmployee] = attendanceEmployeeUser('employee', [
        'email' => 'available.employee@example.com',
    ], [
        'employee_id' => 'EMP-30003',
        'full_name' => 'Available Employee',
        'email' => 'available.employee@example.com',
    ]);

    LeaveRequest::query()->create([
        'employee_id' => $employeeOnLeave->id,
        'leave_type' => 'annual',
        'start_date' => '2026-05-05',
        'end_date' => '2026-05-05',
        'duration_type' => 'full_day',
        'total_days' => 1,
        'reason' => 'Approved leave',
        'status' => 'approved',
    ]);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/attendance/mark-absent')
        ->assertSuccessful()
        ->assertJsonPath('data.created_count', 1);

    expect(Attendance::query()->where('employee_id', $employeeOnLeave->id)->exists())->toBeFalse()
        ->and(Attendance::query()->where('employee_id', $availableEmployee->id)->exists())->toBeTrue();
});

test('mark absent respects employee join date and last working date', function () {
    Carbon::setTestNow('2026-05-05 18:00:00');
    attendanceCompanySettings();

    [, $activeEmployee] = attendanceEmployeeUser();
    [, $futureJoinEmployee] = attendanceEmployeeUser('employee', [
        'email' => 'future.join@example.com',
    ], [
        'employee_id' => 'EMP-40004',
        'full_name' => 'Future Join',
        'email' => 'future.join@example.com',
        'join_date' => '2026-05-06',
    ]);
    [, $endedEmployee] = attendanceEmployeeUser('employee', [
        'email' => 'ended.employee@example.com',
    ], [
        'employee_id' => 'EMP-50005',
        'full_name' => 'Ended Employee',
        'email' => 'ended.employee@example.com',
        'employment_status' => 'terminated',
        'last_working_date' => '2026-05-04',
    ]);
    [, $lastDayEmployee] = attendanceEmployeeUser('employee', [
        'email' => 'last.day@example.com',
    ], [
        'employee_id' => 'EMP-60006',
        'full_name' => 'Last Day Employee',
        'email' => 'last.day@example.com',
        'employment_status' => 'resigned',
        'last_working_date' => '2026-05-05',
    ]);

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/attendance/mark-absent')
        ->assertSuccessful()
        ->assertJsonPath('data.created_count', 2);

    expect(Attendance::query()->where('employee_id', $activeEmployee->id)->exists())->toBeTrue()
        ->and(Attendance::query()->where('employee_id', $futureJoinEmployee->id)->exists())->toBeFalse()
        ->and(Attendance::query()->where('employee_id', $endedEmployee->id)->exists())->toBeFalse()
        ->and(Attendance::query()->where('employee_id', $lastDayEmployee->id)->exists())->toBeTrue();
});

// ─── QR Code Attendance ───────────────────────────────────────────────────────

use App\Models\AttendanceQrToken;

test('hr can generate a qr token and response includes token and scan_url', function () {
    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/attendance/qr/generate')
        ->assertCreated()
        ->assertJsonPath('message', 'QR token ready.')
        ->assertJsonStructure(['data' => ['id', 'token', 'scan_url', 'generated_by', 'created_at']]);

    expect(AttendanceQrToken::query()->count())->toBe(1);
});

test('generate qr is idempotent and returns the existing token on repeated calls', function () {
    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $first = $this->withToken($token)
        ->postJson('/api/attendance/qr/generate')
        ->assertCreated()
        ->json('data.token');

    $second = $this->withToken($token)
        ->postJson('/api/attendance/qr/generate')
        ->assertCreated()
        ->json('data.token');

    expect($first)->toBe($second)
        ->and(AttendanceQrToken::query()->count())->toBe(1);
});

test('admin can generate a qr token', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $token = $admin->createToken('admin-device')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/attendance/qr/generate')
        ->assertCreated();
});

test('employee cannot generate a qr token', function () {
    [$user] = attendanceEmployeeUser();
    $token = $user->createToken('employee-device')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/attendance/qr/generate')
        ->assertForbidden();
});

test('current qr returns active token when one exists', function () {
    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $generated = $this->withToken($token)
        ->postJson('/api/attendance/qr/generate')
        ->json('data.token');

    $this->withToken($token)
        ->getJson('/api/attendance/qr/current')
        ->assertSuccessful()
        ->assertJsonPath('data.token', $generated);
});

test('current qr returns null when no token exists', function () {
    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/attendance/qr/current')
        ->assertSuccessful()
        ->assertJsonPath('data', null);
});

test('hr can delete a qr token and it is removed from the database', function () {
    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $id = $this->withToken($token)
        ->postJson('/api/attendance/qr/generate')
        ->json('data.id');

    $this->withToken($token)
        ->deleteJson("/api/attendance/qr/{$id}")
        ->assertSuccessful()
        ->assertJsonPath('message', 'QR token deleted successfully.');

    expect(AttendanceQrToken::query()->count())->toBe(0);
});

test('after deleting a qr token generate creates a fresh one', function () {
    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $first = $this->withToken($token)->postJson('/api/attendance/qr/generate')->json('data');
    $this->withToken($token)->deleteJson("/api/attendance/qr/{$first['id']}")->assertSuccessful();

    $second = $this->withToken($token)->postJson('/api/attendance/qr/generate')->json('data');

    expect($second['token'])->not->toBe($first['token'])
        ->and(AttendanceQrToken::query()->count())->toBe(1);
});

test('employee cannot delete a qr token', function () {
    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $hrToken = $hr->createToken('hr-device')->plainTextToken;

    $id = $this->withToken($hrToken)->postJson('/api/attendance/qr/generate')->json('data.id');

    [$user] = attendanceEmployeeUser();
    $empToken = $user->createToken('employee-device')->plainTextToken;

    $this->withToken($empToken)
        ->deleteJson("/api/attendance/qr/{$id}")
        ->assertForbidden();
});

test('hr can download the qr code as a png file', function () {
    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $id = $this->withToken($token)->postJson('/api/attendance/qr/generate')->json('data.id');

    $response = $this->withToken($token)->get("/api/attendance/qr/{$id}/download");

    $response->assertSuccessful();
    expect($response->headers->get('Content-Type'))->toContain('image/png');
});

test('employee cannot download the qr code', function () {
    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $hrToken = $hr->createToken('hr-device')->plainTextToken;

    $id = $this->withToken($hrToken)->postJson('/api/attendance/qr/generate')->json('data.id');

    [$user] = attendanceEmployeeUser();
    $empToken = $user->createToken('employee-device')->plainTextToken;

    $this->withToken($empToken)
        ->get("/api/attendance/qr/{$id}/download")
        ->assertForbidden();
});

test('employee can scan qr token and auto clock in with late detection and no gps stored', function () {
    Carbon::setTestNow('2026-05-05 08:30:00');
    attendanceCompanySettings();

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $hrToken = $hr->createToken('hr-device')->plainTextToken;
    $qrTokenValue = $this->withToken($hrToken)->postJson('/api/attendance/qr/generate')->json('data.token');

    [$user, $employee] = attendanceEmployeeUser();
    $empToken = $user->createToken('employee-device')->plainTextToken;

    $this->withToken($empToken)
        ->postJson('/api/attendance/qr/scan', ['token' => $qrTokenValue])
        ->assertCreated()
        ->assertJsonPath('message', 'QR clock-in successful.')
        ->assertJsonPath('data.action', 'qr_clock_in')
        ->assertJsonPath('data.attendance.status', 'late')
        ->assertJsonPath('data.attendance.is_late', true)
        ->assertJsonPath('data.attendance.qr_clock_in', true);

    $attendance = $employee->attendances()->sole();
    expect($attendance->clock_in_latitude)->toBeNull()
        ->and($attendance->clock_in_longitude)->toBeNull()
        ->and($attendance->qr_clock_in)->toBeTrue();
});

test('employee can scan qr token again after clock in to auto clock out', function () {
    Carbon::setTestNow('2026-05-05 07:55:00');
    attendanceCompanySettings();

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $hrToken = $hr->createToken('hr-device')->plainTextToken;
    $qrTokenValue = $this->withToken($hrToken)->postJson('/api/attendance/qr/generate')->json('data.token');

    [$user, $employee] = attendanceEmployeeUser();
    $empToken = $user->createToken('employee-device')->plainTextToken;

    $this->withToken($empToken)
        ->postJson('/api/attendance/qr/scan', ['token' => $qrTokenValue])
        ->assertCreated()
        ->assertJsonPath('data.action', 'qr_clock_in');

    Carbon::setTestNow('2026-05-05 17:10:00');

    $this->withToken($empToken)
        ->postJson('/api/attendance/qr/scan', ['token' => $qrTokenValue])
        ->assertSuccessful()
        ->assertJsonPath('message', 'QR clock-out successful.')
        ->assertJsonPath('data.action', 'qr_clock_out')
        ->assertJsonPath('data.attendance.qr_clock_out', true);

    $attendance = $employee->attendances()->sole();
    expect($attendance->clock_out_time)->not->toBeNull()
        ->and($attendance->clock_out_latitude)->toBeNull()
        ->and($attendance->qr_clock_out)->toBeTrue();
});

test('qr scan is rejected when employee has already completed attendance for today', function () {
    Carbon::setTestNow('2026-05-05 07:55:00');
    attendanceCompanySettings();

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $hrToken = $hr->createToken('hr-device')->plainTextToken;
    $qrTokenValue = $this->withToken($hrToken)->postJson('/api/attendance/qr/generate')->json('data.token');

    [$user, $employee] = attendanceEmployeeUser();
    $empToken = $user->createToken('employee-device')->plainTextToken;

    Attendance::query()->create([
        'employee_id'     => $employee->id,
        'attendance_date' => '2026-05-05',
        'clock_in_time'   => '2026-05-05 07:55:00',
        'clock_out_time'  => '2026-05-05 17:00:00',
        'status'          => 'present',
        'is_late'         => false,
    ]);

    $this->withToken($empToken)
        ->postJson('/api/attendance/qr/scan', ['token' => $qrTokenValue])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'You have already completed your attendance for today.');
});

test('qr scan is rejected for a non-existent token', function () {
    attendanceCompanySettings();

    [$user] = attendanceEmployeeUser();
    $empToken = $user->createToken('employee-device')->plainTextToken;

    $fakeToken = str_repeat('x', 64);

    $this->withToken($empToken)
        ->postJson('/api/attendance/qr/scan', ['token' => $fakeToken])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Invalid QR code.');
});

test('qr scan rejects token shorter than 64 characters with validation error', function () {
    [$user] = attendanceEmployeeUser();
    $empToken = $user->createToken('employee-device')->plainTextToken;

    $this->withToken($empToken)
        ->postJson('/api/attendance/qr/scan', ['token' => 'tooshort'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('token');
});

test('qr scan is rejected for an employee on approved leave today', function () {
    Carbon::setTestNow('2026-05-05 08:00:00');
    attendanceCompanySettings();

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $hrToken = $hr->createToken('hr-device')->plainTextToken;
    $qrTokenValue = $this->withToken($hrToken)->postJson('/api/attendance/qr/generate')->json('data.token');

    [$user, $employee] = attendanceEmployeeUser();
    $empToken = $user->createToken('employee-device')->plainTextToken;

    LeaveRequest::query()->create([
        'employee_id'   => $employee->id,
        'leave_type'    => 'annual',
        'start_date'    => '2026-05-05',
        'end_date'      => '2026-05-05',
        'duration_type' => 'full_day',
        'total_days'    => 1,
        'reason'        => 'Annual leave',
        'status'        => 'approved',
    ]);

    $this->withToken($empToken)
        ->postJson('/api/attendance/qr/scan', ['token' => $qrTokenValue])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'You are on approved leave today and cannot use QR attendance.');

    expect(Attendance::query()->count())->toBe(0);
});

test('qr scan returns 403 when user has no employee profile', function () {
    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $hrToken = $hr->createToken('hr-device')->plainTextToken;
    $qrTokenValue = $this->withToken($hrToken)->postJson('/api/attendance/qr/generate')->json('data.token');

    $user = User::factory()->create();
    $user->assignRole('employee');
    $empToken = $user->createToken('employee-device')->plainTextToken;

    $this->withToken($empToken)
        ->postJson('/api/attendance/qr/scan', ['token' => $qrTokenValue])
        ->assertForbidden()
        ->assertJsonPath('message', 'No employee profile is linked to this user account.');
});

test('qr scan audit log is written for clock in and clock out', function () {
    Carbon::setTestNow('2026-05-05 07:55:00');
    attendanceCompanySettings();

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $hrToken = $hr->createToken('hr-device')->plainTextToken;
    $qrTokenValue = $this->withToken($hrToken)->postJson('/api/attendance/qr/generate')->json('data.token');

    [$user] = attendanceEmployeeUser();
    $empToken = $user->createToken('employee-device')->plainTextToken;

    $this->withToken($empToken)->postJson('/api/attendance/qr/scan', ['token' => $qrTokenValue]);

    expect(Activity::query()->where('log_name', 'attendance')->where('description', 'qr_clock_in')->exists())->toBeTrue();

    Carbon::setTestNow('2026-05-05 17:05:00');
    $this->withToken($empToken)->postJson('/api/attendance/qr/scan', ['token' => $qrTokenValue]);

    expect(Activity::query()->where('log_name', 'attendance')->where('description', 'qr_clock_out')->exists())->toBeTrue();
});

test('two rapid qr scans from same employee only create one attendance record', function () {
    Carbon::setTestNow('2026-05-05 07:55:00');
    attendanceCompanySettings();

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $hrToken = $hr->createToken('hr-device')->plainTextToken;
    $qrTokenValue = $this->withToken($hrToken)->postJson('/api/attendance/qr/generate')->json('data.token');

    [$user] = attendanceEmployeeUser();
    $empToken = $user->createToken('employee-device')->plainTextToken;

    $this->withToken($empToken)->postJson('/api/attendance/qr/scan', ['token' => $qrTokenValue])->assertCreated();
    $this->withToken($empToken)->postJson('/api/attendance/qr/scan', ['token' => $qrTokenValue])->assertSuccessful();

    expect(Attendance::query()->count())->toBe(1);
});
