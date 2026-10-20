<?php

use App\Models\Attendance;
use App\Models\AttendanceQrToken;
use App\Models\CompanySetting;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schedule;
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

test('early morning attendance is present through manual and qr check in', function (string $channel, string $time) {
    Carbon::setTestNow('2026-05-05 '.$time);
    attendanceCompanySettings();
    [$user] = attendanceEmployeeUser();
    $token = $user->createToken('employee-device')->plainTextToken;
    $endpoint = '/api/attendance/clock-in';
    $payload = ATTENDANCE_VALID_GPS;

    if ($channel === 'qr') {
        $qr = app(AttendanceService::class)->generateQrToken($user);
        $endpoint = '/api/attendance/qr/scan';
        $payload['token'] = $qr->token;
    }

    $attendancePath = $channel === 'qr' ? 'data.attendance' : 'data';
    $this->withToken($token)->postJson($endpoint, $payload)
        ->assertCreated()
        ->assertJsonPath($attendancePath.'.status', 'present')
        ->assertJsonPath($attendancePath.'.is_late', false);

    expect(Attendance::query()->sole()->clock_in_time->format('H:i:s'))->toBe($time);

    $this->withToken($token)->postJson($endpoint, $payload)->assertUnprocessable();
    expect(Attendance::query()->count())->toBe(1);
})->with(['manual', 'qr'])->with(['00:00:00', '05:00:00', '07:59:59', '08:00:00']);

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

test('duplicate clock in and duplicate clock out are prevented and clock out requires the afternoon window', function () {
    attendanceCompanySettings();
    [$user, $employee] = attendanceEmployeeUser();
    $token = $user->createToken('employee-device')->plainTextToken;

    Carbon::setTestNow('2026-05-05 08:00:00');

    $this->withToken($token)
        ->postJson('/api/attendance/clock-out', ATTENDANCE_VALID_GPS)
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Clock Out is available from 1:00 PM.');

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
    Carbon::setTestNow('2026-05-05 08:00:00');
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
        ->getJson("/api/attendance?employee_id={$otherEmployee->employee_id}&status=present")
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.employee.id', $otherEmployee->id);
});

test('late attendance filter includes late records with missing clock-out', function () {
    attendanceCompanySettings();
    [, $employee] = attendanceEmployeeUser();

    Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-05-05',
        'clock_in_time' => '2026-05-05 08:15:00',
        'status' => 'missing_clock_out',
        'is_late' => true,
    ]);

    $hr = User::factory()->create();
    $hr->assignRole('hr');

    $this->withToken($hr->createToken('hr-device')->plainTextToken)
        ->getJson("/api/attendance?employee_id={$employee->employee_id}&status=late")
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.status', 'missing_clock_out')
        ->assertJsonPath('data.0.is_late', true);
});

test('hr can switch between own and team attendance without exposing another employee in own scope', function () {
    [$hr, $hrEmployee] = attendanceEmployeeUser('hr', [], ['full_name' => 'HR Employee']);
    [, $otherEmployee] = attendanceEmployeeUser('employee', [], ['full_name' => 'Other Employee']);

    foreach ([$hrEmployee, $otherEmployee] as $employee) {
        Attendance::query()->create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-10-01',
            'status' => 'present',
            'is_late' => false,
        ]);
    }

    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/attendance?scope=own')
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.employee.id', $hrEmployee->id);

    $this->withToken($token)
        ->getJson("/api/attendance?scope=own&employee_id={$otherEmployee->employee_id}&employee_name=Other")
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.employee.id', $hrEmployee->id);

    $this->withToken($token)
        ->getJson('/api/attendance?scope=team')
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 2);

    $employeeToken = $otherEmployee->user->createToken('employee-device')->plainTextToken;
    auth()->forgetGuards();
    $this->withToken($employeeToken)
        ->getJson('/api/attendance?scope=team')
        ->assertForbidden();

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $adminToken = $admin->createToken('admin-device')->plainTextToken;
    auth()->forgetGuards();
    $this->withToken($adminToken)
        ->getJson('/api/attendance?scope=own')
        ->assertForbidden();
});

test('attendance list filters by employee name and department together', function () {
    $engineering = Department::query()->create(['name' => 'Engineering', 'status' => 'active']);
    $finance = Department::query()->create(['name' => 'Finance', 'status' => 'active']);

    [, $alexEngineering] = attendanceEmployeeUser('employee', [], [
        'full_name' => 'Alex Kim',
        'department_id' => $engineering->id,
    ]);
    [, $alexFinance] = attendanceEmployeeUser('employee', [], [
        'full_name' => 'Alex Lee',
        'department_id' => $finance->id,
    ]);
    [, $mariaEngineering] = attendanceEmployeeUser('employee', [], [
        'full_name' => 'Maria Chen',
        'department_id' => $engineering->id,
    ]);

    foreach ([$alexEngineering, $alexFinance, $mariaEngineering] as $employee) {
        Attendance::query()->create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-10-01',
            'status' => 'present',
            'is_late' => false,
        ]);
    }

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/attendance?employee_name=aLeX')
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 2);

    $this->withToken($token)
        ->getJson("/api/attendance?department_id={$engineering->id}")
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 2);

    $this->withToken($token)
        ->getJson("/api/attendance?employee_name=alex&department_id={$engineering->id}&per_page=1")
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.employee.id', $alexEngineering->id);
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

test('hr cannot correct their own attendance but can correct another employee', function () {
    attendanceCompanySettings();

    [$hr, $hrEmployee] = attendanceEmployeeUser('hr');
    [, $otherEmployee] = attendanceEmployeeUser();

    $ownAttendance = Attendance::query()->create([
        'employee_id' => $hrEmployee->id,
        'attendance_date' => '2026-10-01',
        'status' => 'present',
        'is_late' => false,
    ]);
    $otherAttendance = Attendance::query()->create([
        'employee_id' => $otherEmployee->id,
        'attendance_date' => '2026-10-01',
        'status' => 'present',
        'is_late' => false,
    ]);

    $token = $hr->createToken('hr-device')->plainTextToken;
    $correction = [
        'status' => 'absent',
        'correction_reason' => 'Verified attendance record.',
    ];

    $this->withToken($token)
        ->putJson("/api/attendance/{$ownAttendance->id}/correction", $correction)
        ->assertForbidden();

    expect($ownAttendance->fresh()->status)->toBe('present')
        ->and($ownAttendance->fresh()->corrected_by)->toBeNull();

    $this->withToken($token)
        ->putJson("/api/attendance/{$otherAttendance->id}/correction", $correction)
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'absent');
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

test('manual late correction remains late after a missing clock out', function () {
    Carbon::setTestNow('2026-10-02 10:00:00');
    attendanceCompanySettings();

    [, $employee] = attendanceEmployeeUser();
    $attendance = Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-10-02',
        'clock_in_time' => '2026-10-02 07:59:22',
        'status' => 'present',
        'is_late' => false,
    ]);
    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->putJson("/api/attendance/{$attendance->id}/correction", [
            'status' => 'late',
            'correction_reason' => 'Manual late decision.',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.is_late', true);

    Carbon::setTestNow('2026-10-02 20:00:00');
    $this->artisan('attendance:mark-missing-clock-out')->assertSuccessful();

    expect($attendance->fresh()->status)->toBe('late')
        ->and($attendance->fresh()->is_late)->toBeTrue();
});

test('mark absent uses today when no attendance date is provided', function () {
    Carbon::setTestNow('2026-05-04 23:00:00');
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

test('morning clock-in check is scheduled at 0930', function () {
    $event = collect(Schedule::events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'attendance:check-missing-clock-in'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 9 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});

test('morning clock-in check flags only employees expected in the morning', function (string $duration, ?string $period, string $status, bool $expected) {
    Carbon::setTestNow('2026-05-05 09:30:00');
    attendanceCompanySettings();
    [, $employee] = attendanceEmployeeUser();
    LeaveRequest::query()->create([
        'employee_id' => $employee->id,
        'leave_type' => 'annual',
        'start_date' => '2026-05-04',
        'end_date' => '2026-05-06',
        'duration_type' => $duration,
        'half_day_period' => $period,
        'total_days' => $duration === 'full_day' ? 3 : 0.5,
        'reason' => 'Leave eligibility check',
        'status' => $status,
    ]);

    $this->artisan('attendance:check-missing-clock-in')->assertSuccessful();
    $attendance = $employee->attendances()->first();
    expect($attendance !== null)->toBe($expected);
    if ($expected) {
        expect($attendance->status)->toBe('missing_clock_in')
            ->and($attendance->absent_periods)->toBe([])
            ->and($attendance->clock_in_time)->toBeNull();
    }
})->with([
    'approved full day is excluded' => ['full_day', null, 'approved', false],
    'approved morning is excluded' => ['half_day', 'morning', 'approved', false],
    'approved afternoon is included' => ['half_day', 'afternoon', 'approved', true],
    'pending morning does not excuse attendance' => ['half_day', 'morning', 'pending', true],
]);

test('morning clock-in check respects employment dates clock-ins and HR corrections', function () {
    Carbon::setTestNow('2026-05-05 09:30:00');
    attendanceCompanySettings();
    [, $eligible] = attendanceEmployeeUser('employee', [], ['join_date' => '2026-05-05', 'last_working_date' => '2026-05-05']);
    [, $future] = attendanceEmployeeUser('employee', [], ['join_date' => '2026-05-06']);
    [, $ended] = attendanceEmployeeUser('employee', [], ['last_working_date' => '2026-05-04']);
    [, $clockedIn] = attendanceEmployeeUser();
    [, $corrected] = attendanceEmployeeUser();
    $present = Attendance::query()->create([
        'employee_id' => $clockedIn->id,
        'attendance_date' => '2026-05-05',
        'clock_in_time' => '2026-05-05 08:00:00',
        'status' => 'present',
    ]);
    $manual = Attendance::query()->create([
        'employee_id' => $corrected->id,
        'attendance_date' => '2026-05-05',
        'status' => 'present',
        'corrected_at' => now(),
    ]);

    $this->artisan('attendance:check-missing-clock-in')->assertSuccessful();
    $this->artisan('attendance:check-missing-clock-in')->assertSuccessful();

    expect($eligible->attendances()->sole()->absent_periods)->toBe([])
        ->and($future->attendances()->count())->toBe(0)
        ->and($ended->attendances()->count())->toBe(0)
        ->and($present->fresh()->status)->toBe('present')
        ->and($manual->fresh()->status)->toBe('present')
        ->and(Attendance::query()->count())->toBe(3);
});

test('morning clock-in check skips non-working days and public holidays', function (bool $holiday) {
    Carbon::setTestNow($holiday ? '2026-05-05 09:30:00' : '2026-05-03 09:30:00');
    attendanceCompanySettings();
    attendanceEmployeeUser();
    if ($holiday) {
        PublicHoliday::query()->create([
            'name' => 'Public holiday',
            'holiday_date' => '2026-05-05',
            'status' => 'active',
        ]);
    }

    $this->artisan('attendance:check-missing-clock-in')->assertSuccessful();
    expect(Attendance::query()->count())->toBe(0);
})->with(['non-working day' => false, 'public holiday' => true]);

test('morning clock-in check preserves previously recorded absent periods without duplicates', function () {
    Carbon::setTestNow('2026-05-05 09:30:00');
    attendanceCompanySettings();
    [, $employee] = attendanceEmployeeUser();
    $record = Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-05-05',
        'status' => 'absent',
        'absent_periods' => ['afternoon'],
    ]);

    $this->artisan('attendance:check-missing-clock-in')->assertSuccessful();
    $this->artisan('attendance:check-missing-clock-in')->assertSuccessful();
    expect($record->fresh()->absent_periods)->toBe(['afternoon'])
        ->and($employee->attendances()->count())->toBe(1);
});

test('later morning clock-in reuses the flagged record and clears its absence', function (string $channel) {
    Carbon::setTestNow('2026-05-05 09:30:00');
    attendanceCompanySettings();
    [$user, $employee] = attendanceEmployeeUser();
    $this->artisan('attendance:check-missing-clock-in')->assertSuccessful();
    $recordId = $employee->attendances()->sole()->id;
    Carbon::setTestNow('2026-05-05 10:00:00');
    $service = app(AttendanceService::class);

    if ($channel === 'gps') {
        $this->actingAs($user)->postJson('/api/attendance/clock-in', ATTENDANCE_VALID_GPS)
            ->assertCreated()->assertJsonPath('data.status', 'late');
    } elseif ($channel === 'qr') {
        $token = $service->generateQrToken($user);
        $result = $service->scanQr($user, $token->token, ...array_values(ATTENDANCE_VALID_GPS));
        expect($result['action'])->toBe('qr_clock_in');
    } else {
        $service->proxyClockIn($user, $employee->id, '2026-05-05');
    }

    $record = $employee->attendances()->sole();
    expect($record->id)->toBe($recordId)
        ->and($record->clock_in_time)->not->toBeNull()
        ->and($record->clock_out_time)->toBeNull()
        ->and($record->absent_periods)->toBe([])
        ->and($record->status)->toBe($channel === 'proxy' ? 'present' : 'late');

    $this->artisan('attendance:check-missing-clock-in')->assertSuccessful();
    expect($record->fresh()->absent_periods)->toBe([]);
})->with(['gps', 'qr', 'proxy']);

test('afternoon arrival retains the missing clock-in flag and HR correction can clear it', function () {
    Carbon::setTestNow('2026-05-05 09:30:00');
    attendanceCompanySettings();
    [$user, $employee] = attendanceEmployeeUser();
    $this->artisan('attendance:check-missing-clock-in')->assertSuccessful();
    Carbon::setTestNow('2026-05-05 13:30:00');
    $service = app(AttendanceService::class);
    $record = $service->clockOut($user, ...array_values(ATTENDANCE_VALID_GPS));
    expect($record->status)->toBe('missing_clock_in')->and($record->absent_periods)->toBe([]);

    $service->correct($record, [
        'clock_in_time' => '2026-05-05 08:00:00',
        'status' => 'present',
        'correction_reason' => 'Forgot to clock in',
    ], $user);
    expect($record->fresh()->absent_periods)->toBe([])->and($record->fresh()->status)->toBe('present');
});

test('morning clock-in command rejects future and malformed dates', function (string $date) {
    Carbon::setTestNow('2026-05-05 09:30:00');
    $this->artisan('attendance:check-missing-clock-in', ['--date' => $date])->assertFailed();
    expect(Attendance::query()->count())->toBe(0);
})->with(['2026-05-06', 'invalid-date', '2026-02-30']);

test('scheduled absence command marks today and is idempotent', function () {
    Carbon::setTestNow('2026-10-02 23:00:00');
    attendanceCompanySettings();
    [, $employee] = attendanceEmployeeUser();

    $this->artisan('attendance:mark-absent')->assertSuccessful();

    $absence = Attendance::query()->where('employee_id', $employee->id)->sole();
    expect($absence->attendance_date->toDateString())->toBe('2026-10-02')
        ->and($absence->status)->toBe('absent');

    $this->artisan('attendance:mark-absent')->assertSuccessful();
    expect(Attendance::query()->where('employee_id', $employee->id)->count())->toBe(1);
});

test('missing work after approved half-day leave counts as half an absence', function (string $period) {
    Carbon::setTestNow('2026-05-05 23:00:00');
    attendanceCompanySettings();

    [$user, $employee] = attendanceEmployeeUser('employee', [], ['join_date' => '2026-05-05']);
    LeaveRequest::query()->create([
        'employee_id' => $employee->id,
        'leave_type' => 'annual',
        'start_date' => '2026-05-05',
        'end_date' => '2026-05-05',
        'duration_type' => 'half_day',
        'half_day_period' => $period,
        'total_days' => 0.5,
        'reason' => 'Approved half-day leave',
        'status' => 'approved',
    ]);

    $this->artisan('attendance:mark-absent')
        ->assertSuccessful();

    expect(Attendance::query()
        ->where('employee_id', $employee->id)
        ->whereDate('attendance_date', '2026-05-05')
        ->value('status'))->toBe('absent');

    $token = $user->createToken('employee-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/attendance/summary?month=5&year=2026')
        ->assertSuccessful()
        ->assertJsonPath('data.summary.absent', 0.5);
})->with(['morning', 'afternoon']);

test('unrecorded half-day absence is not counted before the final cutoff', function () {
    Carbon::setTestNow('2026-05-05 18:00:00');
    attendanceCompanySettings();

    [$user, $employee] = attendanceEmployeeUser('employee', [], ['join_date' => '2026-05-05']);
    LeaveRequest::query()->create([
        'employee_id' => $employee->id,
        'leave_type' => 'annual',
        'start_date' => '2026-05-05',
        'end_date' => '2026-05-05',
        'duration_type' => 'half_day',
        'half_day_period' => 'morning',
        'total_days' => 0.5,
        'reason' => 'Approved morning leave',
        'status' => 'approved',
    ]);
    $token = $user->createToken('employee-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/attendance/summary?month=5&year=2026')
        ->assertSuccessful()
        ->assertJsonPath('data.summary.absent', 0);

    expect(Attendance::query()->where('employee_id', $employee->id)->exists())->toBeFalse();
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
    Carbon::setTestNow('2026-05-05 23:00:00');
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
    Carbon::setTestNow('2026-05-05 23:00:00');
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
    Carbon::setTestNow('2026-05-05 23:00:00');
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
    Carbon::setTestNow('2026-05-05 23:00:00');
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

test('attendance summary counts recorded and unrecorded absences and late by is_late', function () {
    Carbon::setTestNow('2026-05-08 10:00:00');
    attendanceCompanySettings();

    [$user, $employee] = attendanceEmployeeUser();
    $token = $user->createToken('employee-device')->plainTextToken;

    Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-05-04',
        'clock_in_time' => '2026-05-04 08:00:00',
        'clock_out_time' => '2026-05-04 17:00:00',
        'status' => 'present',
        'is_late' => false,
    ]);
    Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-05-05',
        'clock_in_time' => '2026-05-05 08:30:00',
        'clock_out_time' => '2026-05-05 17:00:00',
        'status' => 'late',
        'is_late' => true,
    ]);
    Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-05-06',
        'status' => 'absent',
        'is_late' => false,
    ]);
    Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-05-07',
        'status' => 'absent',
        'is_late' => false,
    ]);

    $this->withToken($token)
        ->getJson('/api/attendance/summary?month=5&year=2026')
        ->assertSuccessful()
        ->assertJsonPath('data.summary.present', 1)
        ->assertJsonPath('data.summary.late', 1)
        ->assertJsonPath('data.summary.absent', 3)
        ->assertJsonPath('data.summary.missing_clock_out', 0)
        ->assertJsonPath('data.summary.attended_days', 2);
});

test('hr personal summary counts october 1 as absent without an attendance row', function () {
    Carbon::setTestNow('2026-10-02 10:00:00');
    attendanceCompanySettings();

    [$hr, $employee] = attendanceEmployeeUser('hr', [], [
        'join_date' => '2026-09-08',
    ]);
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/attendance/summary?month=10&year=2026')
        ->assertSuccessful()
        ->assertJsonPath('data.summary.absent', 1)
        ->assertJsonPath('data.summary.working_days_in_period', 2)
        ->assertJsonPath('data.today', null);

    expect(Attendance::query()->where('employee_id', $employee->id)->exists())->toBeFalse();

    LeaveRequest::query()->create([
        'employee_id' => $employee->id,
        'leave_type' => 'annual',
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-01',
        'duration_type' => 'full_day',
        'total_days' => 1,
        'reason' => 'Approved leave',
        'status' => 'approved',
    ]);

    $this->withToken($token)
        ->getJson('/api/attendance/summary?month=10&year=2026')
        ->assertSuccessful()
        ->assertJsonPath('data.summary.absent', 0);
});

test('personal and team summaries count a manually marked late record', function () {
    Carbon::setTestNow('2026-10-02 10:00:00');
    attendanceCompanySettings();

    [$hr, $employee] = attendanceEmployeeUser('hr', [], ['join_date' => '2026-10-02']);
    Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-10-02',
        'clock_in_time' => '2026-10-02 07:59:22',
        'status' => 'late',
        'is_late' => false,
    ]);

    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/attendance/summary?month=10&year=2026')
        ->assertSuccessful()
        ->assertJsonPath('data.summary.late', 1);

    $this->withToken($token)
        ->getJson('/api/attendance/team-summary?month=10&year=2026')
        ->assertSuccessful()
        ->assertJsonPath('data.summary.late', 1);
});

test('october 2026 summary includes a qr scan and caps working days at today', function () {
    Carbon::setTestNow('2026-10-02 08:30:00');
    attendanceCompanySettings();

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $hrToken = $hr->createToken('hr-device')->plainTextToken;
    $qrTokenValue = $this->withToken($hrToken)->postJson('/api/attendance/qr/generate')->json('data.token');

    [$user, $employee] = attendanceEmployeeUser();
    $empToken = $user->createToken('employee-device')->plainTextToken;
    auth()->forgetGuards();

    $this->withToken($empToken)
        ->postJson('/api/attendance/qr/scan', ['token' => $qrTokenValue, ...ATTENDANCE_VALID_GPS])
        ->assertCreated();

    $this->withToken($empToken)
        ->getJson('/api/attendance/summary?month=10&year=2026')
        ->assertSuccessful()
        ->assertJsonPath('data.employee.id', $employee->id)
        ->assertJsonPath('data.period.from', '2026-10-01')
        ->assertJsonPath('data.period.to', '2026-10-31')
        ->assertJsonPath('data.summary.present', 0)
        ->assertJsonPath('data.summary.late', 1)
        ->assertJsonPath('data.summary.absent', 1)
        ->assertJsonPath('data.summary.attended_days', 0.5)
        ->assertJsonPath('data.summary.working_days_in_period', 2)
        ->assertJsonPath('data.summary.attendance_rate', '25.00')
        ->assertJsonPath('data.today.status', 'late')
        ->assertJsonPath('data.today.is_late', true);
});

test('team summary counts all employee records, including late missing clock outs', function () {
    Carbon::setTestNow('2026-10-02 10:00:00');
    attendanceCompanySettings();

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $hrToken = $hr->createToken('hr-device')->plainTextToken;

    [$employeeUser, $firstEmployee] = attendanceEmployeeUser();
    [, $secondEmployee] = attendanceEmployeeUser();
    [, $unrecordedEmployee] = attendanceEmployeeUser();
    [, $onLeaveEmployee] = attendanceEmployeeUser();

    LeaveRequest::query()->create([
        'employee_id' => $onLeaveEmployee->id,
        'leave_type' => 'annual',
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-01',
        'duration_type' => 'full_day',
        'total_days' => 1,
        'reason' => 'Approved leave',
        'status' => 'approved',
    ]);

    Attendance::query()->create([
        'employee_id' => $firstEmployee->id,
        'attendance_date' => '2026-10-01',
        'status' => 'missing_clock_out',
        'is_late' => true,
        'clock_in_time' => '2026-10-01 08:30:00',
    ]);
    Attendance::query()->create([
        'employee_id' => $secondEmployee->id,
        'attendance_date' => '2026-10-01',
        'status' => 'absent',
        'is_late' => false,
    ]);
    Attendance::query()->create([
        'employee_id' => $firstEmployee->id,
        'attendance_date' => '2026-10-02',
        'status' => 'present',
        'is_late' => false,
        'clock_in_time' => '2026-10-02 07:55:00',
    ]);
    Attendance::query()->create([
        'employee_id' => $secondEmployee->id,
        'attendance_date' => '2026-10-03',
        'status' => 'absent',
        'is_late' => false,
    ]);

    $this->withToken($hrToken)
        ->getJson('/api/attendance/team-summary?month=10&year=2026')
        ->assertSuccessful()
        ->assertJsonPath('data.summary.total_records', 4)
        ->assertJsonPath('data.summary.present', 1)
        ->assertJsonPath('data.summary.late', 1)
        ->assertJsonPath('data.summary.absent', 2)
        ->assertJsonPath('data.summary.missing_clock_out', 1);

    expect(Attendance::query()->where('employee_id', $unrecordedEmployee->id)->exists())->toBeFalse();

    $employeeToken = $employeeUser->createToken('employee-device')->plainTextToken;
    auth()->forgetGuards();
    $this->withToken($employeeToken)
        ->getJson('/api/attendance/team-summary?month=10&year=2026')
        ->assertForbidden();
});

test('attendance summary does not modify attendance while reading', function () {
    Carbon::setTestNow('2026-05-05 19:00:00');
    attendanceCompanySettings([
        'working_end_time' => '17:00:00',
    ]);

    [$user, $employee] = attendanceEmployeeUser();
    $token = $user->createToken('employee-device')->plainTextToken;

    $openLate = Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-05-05',
        'clock_in_time' => '2026-05-05 08:30:00',
        'clock_out_time' => null,
        'status' => 'late',
        'is_late' => true,
    ]);

    $this->withToken($token)
        ->getJson('/api/attendance/summary?month=5&year=2026')
        ->assertSuccessful()
        ->assertJsonPath('data.summary.late', 1)
        ->assertJsonPath('data.summary.missing_clock_out', 0)
        ->assertJsonPath('data.summary.attended_days', 0.5);

    expect($openLate->fresh()->status)->toBe('late')
        ->and($openLate->fresh()->is_late)->toBeTrue();
});

test('mark missing clock-out updates eligible records after grace hours', function () {
    Carbon::setTestNow('2026-05-05 19:05:00');
    attendanceCompanySettings([
        'working_end_time' => '17:00:00',
    ]);

    [, $eligible] = attendanceEmployeeUser();
    [, $stillWithinGrace] = attendanceEmployeeUser('employee', [
        'email' => 'within.grace@example.com',
    ], [
        'employee_id' => 'EMP-70007',
        'full_name' => 'Within Grace',
        'email' => 'within.grace@example.com',
    ]);
    [, $alreadyClockedOut] = attendanceEmployeeUser('employee', [
        'email' => 'clocked.out@example.com',
    ], [
        'employee_id' => 'EMP-70008',
        'full_name' => 'Clocked Out',
        'email' => 'clocked.out@example.com',
    ]);

    $eligibleAttendance = Attendance::query()->create([
        'employee_id' => $eligible->id,
        'attendance_date' => '2026-05-05',
        'clock_in_time' => '2026-05-05 08:00:00',
        'clock_out_time' => null,
        'status' => 'present',
        'is_late' => false,
    ]);
    Attendance::query()->create([
        'employee_id' => $alreadyClockedOut->id,
        'attendance_date' => '2026-05-05',
        'clock_in_time' => '2026-05-05 08:00:00',
        'clock_out_time' => '2026-05-05 17:00:00',
        'status' => 'present',
        'is_late' => false,
    ]);

    Carbon::setTestNow('2026-05-05 18:30:00');
    $withinGraceAttendance = Attendance::query()->create([
        'employee_id' => $stillWithinGrace->id,
        'attendance_date' => '2026-05-05',
        'clock_in_time' => '2026-05-05 08:00:00',
        'clock_out_time' => null,
        'status' => 'present',
        'is_late' => false,
    ]);

    Carbon::setTestNow('2026-05-05 18:30:00');

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    // 18:30 is still before 17:00 + 2h = 19:00, so nothing should update yet.
    $this->withToken($token)
        ->postJson('/api/attendance/mark-missing-clock-out', [
            'attendance_date' => '2026-05-05',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.updated_count', 0);

    Carbon::setTestNow('2026-05-05 19:00:00');

    $this->withToken($token)
        ->postJson('/api/attendance/mark-missing-clock-out', [
            'attendance_date' => '2026-05-05',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.attendance_date', '2026-05-05')
        ->assertJsonPath('data.updated_count', 2);

    expect($eligibleAttendance->fresh()->status)->toBe('missing_clock_out')
        ->and($withinGraceAttendance->fresh()->status)->toBe('missing_clock_out')
        ->and(Attendance::query()->where('employee_id', $alreadyClockedOut->id)->value('status'))->toBe('present');
});

test('mark missing clock-out rejects future dates', function () {
    Carbon::setTestNow('2026-05-05 20:00:00');
    attendanceCompanySettings();

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/attendance/mark-missing-clock-out', [
            'attendance_date' => '2026-05-06',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('attendance_date');
});

// ─── QR Code Attendance ───────────────────────────────────────────────────────

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
    auth()->forgetGuards();

    $this->withToken($empToken)
        ->deleteJson("/api/attendance/qr/{$id}")
        ->assertForbidden();
});

test('hr can download the qr code as an svg file', function () {
    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $token = $hr->createToken('hr-device')->plainTextToken;

    $id = $this->withToken($token)->postJson('/api/attendance/qr/generate')->json('data.id');

    $response = $this->withToken($token)->get("/api/attendance/qr/{$id}/download");

    $response->assertSuccessful();
    expect($response->headers->get('Content-Type'))->toContain('image/svg+xml')
        ->and($response->headers->get('Content-Disposition'))->toContain('attendance-qr.svg');
});

test('employee cannot download the qr code', function () {
    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $hrToken = $hr->createToken('hr-device')->plainTextToken;

    $id = $this->withToken($hrToken)->postJson('/api/attendance/qr/generate')->json('data.id');

    [$user] = attendanceEmployeeUser();
    $empToken = $user->createToken('employee-device')->plainTextToken;
    auth()->forgetGuards();

    $this->withToken($empToken)
        ->get("/api/attendance/qr/{$id}/download")
        ->assertForbidden();
});

test('employee can scan qr token within the office radius and auto clock in with late detection', function () {
    Carbon::setTestNow('2026-05-05 08:30:00');
    attendanceCompanySettings();

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $hrToken = $hr->createToken('hr-device')->plainTextToken;
    $qrTokenValue = $this->withToken($hrToken)->postJson('/api/attendance/qr/generate')->json('data.token');

    [$user, $employee] = attendanceEmployeeUser();
    $empToken = $user->createToken('employee-device')->plainTextToken;
    auth()->forgetGuards();

    $this->withToken($empToken)
        ->postJson('/api/attendance/qr/scan', ['token' => $qrTokenValue, ...ATTENDANCE_VALID_GPS])
        ->assertCreated()
        ->assertJsonPath('message', 'QR clock-in successful.')
        ->assertJsonPath('data.action', 'qr_clock_in')
        ->assertJsonPath('data.attendance.status', 'late')
        ->assertJsonPath('data.attendance.is_late', true)
        ->assertJsonPath('data.attendance.qr_clock_in', true);

    $attendance = $employee->attendances()->sole();
    expect((float) $attendance->clock_in_latitude)->toEqualWithDelta(ATTENDANCE_VALID_GPS['latitude'], 0.00000001)
        ->and((float) $attendance->clock_in_longitude)->toEqualWithDelta(ATTENDANCE_VALID_GPS['longitude'], 0.00000001)
        ->and($attendance->qr_clock_in)->toBeTrue();

    $this->withToken($empToken)
        ->getJson('/api/attendance?attendance_date=2026-05-05')
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $attendance->id)
        ->assertJsonPath('data.0.status', 'late')
        ->assertJsonPath('data.0.qr_clock_in', true)
        ->assertJsonPath('data.0.qr_clock_out', false);
});

test('qr scan requires gps coordinates and rejects locations outside the office radius', function () {
    Carbon::setTestNow('2026-05-05 08:00:00');
    attendanceCompanySettings();

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $hrToken = $hr->createToken('hr-device')->plainTextToken;
    $qrTokenValue = $this->withToken($hrToken)->postJson('/api/attendance/qr/generate')->json('data.token');

    [$user] = attendanceEmployeeUser();
    $empToken = $user->createToken('employee-device')->plainTextToken;
    auth()->forgetGuards();

    $this->withToken($empToken)
        ->postJson('/api/attendance/qr/scan', ['token' => $qrTokenValue])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['latitude', 'longitude']);

    $this->withToken($empToken)
        ->postJson('/api/attendance/qr/scan', ['token' => $qrTokenValue, ...ATTENDANCE_OUTSIDE_GPS])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'You are outside the allowed QR scan location.');

    CompanySetting::query()->sole()->update([
        'office_latitude' => null,
        'office_longitude' => null,
    ]);

    $this->withToken($empToken)
        ->postJson('/api/attendance/qr/scan', ['token' => $qrTokenValue, ...ATTENDANCE_VALID_GPS])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Office GPS settings are not configured.');

    expect(Attendance::query()->count())->toBe(0);
});

test('employee can scan qr token again after clock in to auto clock out', function () {
    Carbon::setTestNow('2026-05-05 08:00:00');
    attendanceCompanySettings();

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $hrToken = $hr->createToken('hr-device')->plainTextToken;
    $qrTokenValue = $this->withToken($hrToken)->postJson('/api/attendance/qr/generate')->json('data.token');

    [$user, $employee] = attendanceEmployeeUser();
    $empToken = $user->createToken('employee-device')->plainTextToken;
    auth()->forgetGuards();

    $this->withToken($empToken)
        ->postJson('/api/attendance/qr/scan', ['token' => $qrTokenValue, ...ATTENDANCE_VALID_GPS])
        ->assertCreated()
        ->assertJsonPath('data.action', 'qr_clock_in');

    Carbon::setTestNow('2026-05-05 17:10:00');

    $this->withToken($empToken)
        ->postJson('/api/attendance/qr/scan', ['token' => $qrTokenValue, ...ATTENDANCE_VALID_GPS])
        ->assertSuccessful()
        ->assertJsonPath('message', 'QR clock-out successful.')
        ->assertJsonPath('data.action', 'qr_clock_out')
        ->assertJsonPath('data.attendance.qr_clock_out', true);

    $attendance = $employee->attendances()->sole();
    expect($attendance->clock_out_time)->not->toBeNull()
        ->and((float) $attendance->clock_out_latitude)->toEqualWithDelta(ATTENDANCE_VALID_GPS['latitude'], 0.00000001)
        ->and($attendance->qr_clock_out)->toBeTrue();

    $listed = $this->withToken($empToken)
        ->getJson('/api/attendance?attendance_date=2026-05-05')
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $attendance->id)
        ->assertJsonPath('data.0.qr_clock_in', true)
        ->assertJsonPath('data.0.qr_clock_out', true);

    expect($listed->json('data.0.clock_out_time'))->not->toBeNull();
});

test('qr scan is rejected when employee has already completed attendance for today', function () {
    Carbon::setTestNow('2026-05-05 08:00:00');
    attendanceCompanySettings();

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $hrToken = $hr->createToken('hr-device')->plainTextToken;
    $qrTokenValue = $this->withToken($hrToken)->postJson('/api/attendance/qr/generate')->json('data.token');

    [$user, $employee] = attendanceEmployeeUser();
    $empToken = $user->createToken('employee-device')->plainTextToken;
    auth()->forgetGuards();

    Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-05-05',
        'clock_in_time' => '2026-05-05 07:55:00',
        'clock_out_time' => '2026-05-05 17:00:00',
        'status' => 'present',
        'is_late' => false,
    ]);

    $this->withToken($empToken)
        ->postJson('/api/attendance/qr/scan', ['token' => $qrTokenValue, ...ATTENDANCE_VALID_GPS])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'You have already completed your attendance for today.');
});

test('qr scan is rejected for a non-existent token', function () {
    attendanceCompanySettings();

    [$user] = attendanceEmployeeUser();
    $empToken = $user->createToken('employee-device')->plainTextToken;
    auth()->forgetGuards();

    $fakeToken = str_repeat('x', 64);

    $this->withToken($empToken)
        ->postJson('/api/attendance/qr/scan', ['token' => $fakeToken, ...ATTENDANCE_VALID_GPS])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Invalid QR code.');
});

test('qr scan rejects token shorter than 64 characters with validation error', function () {
    [$user] = attendanceEmployeeUser();
    $empToken = $user->createToken('employee-device')->plainTextToken;
    auth()->forgetGuards();

    $this->withToken($empToken)
        ->postJson('/api/attendance/qr/scan', ['token' => 'tooshort', ...ATTENDANCE_VALID_GPS])
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
    auth()->forgetGuards();

    LeaveRequest::query()->create([
        'employee_id' => $employee->id,
        'leave_type' => 'annual',
        'start_date' => '2026-05-05',
        'end_date' => '2026-05-05',
        'duration_type' => 'full_day',
        'total_days' => 1,
        'reason' => 'Annual leave',
        'status' => 'approved',
    ]);

    $this->withToken($empToken)
        ->postJson('/api/attendance/qr/scan', ['token' => $qrTokenValue, ...ATTENDANCE_VALID_GPS])
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
    auth()->forgetGuards();

    $this->withToken($empToken)
        ->postJson('/api/attendance/qr/scan', ['token' => $qrTokenValue, ...ATTENDANCE_VALID_GPS])
        ->assertForbidden()
        ->assertJsonPath('message', 'No employee profile is linked to this user account.');
});

test('qr scan audit log is written for clock in and clock out', function () {
    Carbon::setTestNow('2026-05-05 08:00:00');
    attendanceCompanySettings();

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $hrToken = $hr->createToken('hr-device')->plainTextToken;
    $qrTokenValue = $this->withToken($hrToken)->postJson('/api/attendance/qr/generate')->json('data.token');

    [$user] = attendanceEmployeeUser();
    $empToken = $user->createToken('employee-device')->plainTextToken;
    auth()->forgetGuards();

    $this->withToken($empToken)->postJson('/api/attendance/qr/scan', ['token' => $qrTokenValue, ...ATTENDANCE_VALID_GPS]);

    expect(Activity::query()->where('log_name', 'attendance')->where('description', 'qr_clock_in')->exists())->toBeTrue();

    Carbon::setTestNow('2026-05-05 17:05:00');
    $this->withToken($empToken)->postJson('/api/attendance/qr/scan', ['token' => $qrTokenValue, ...ATTENDANCE_VALID_GPS]);

    expect(Activity::query()->where('log_name', 'attendance')->where('description', 'qr_clock_out')->exists())->toBeTrue();
});

test('two rapid qr scans from same employee only create one attendance record', function () {
    Carbon::setTestNow('2026-05-05 08:00:00');
    attendanceCompanySettings();

    $hr = User::factory()->create();
    $hr->assignRole('hr');
    $hrToken = $hr->createToken('hr-device')->plainTextToken;
    $qrTokenValue = $this->withToken($hrToken)->postJson('/api/attendance/qr/generate')->json('data.token');

    [$user] = attendanceEmployeeUser();
    $empToken = $user->createToken('employee-device')->plainTextToken;
    auth()->forgetGuards();

    $this->withToken($empToken)->postJson('/api/attendance/qr/scan', ['token' => $qrTokenValue, ...ATTENDANCE_VALID_GPS])->assertCreated();
    $this->withToken($empToken)->postJson('/api/attendance/qr/scan', ['token' => $qrTokenValue, ...ATTENDANCE_VALID_GPS])->assertUnprocessable()->assertJsonPath('message', 'You have already clocked in today.');

    expect(Attendance::query()->count())->toBe(1);
});

test('backend controls attendance action windows and rejects out of window requests', function (string $time, ?string $action) {
    Carbon::setTestNow('2026-05-05 '.$time);
    attendanceCompanySettings();
    [$user] = attendanceEmployeeUser();
    $token = $user->createToken('employee-device')->plainTextToken;

    $state = $this->withToken($token)->getJson('/api/attendance/summary')
        ->assertSuccessful()
        ->assertJsonPath('data.today', null)
        ->assertJsonPath('data.attendance_actions.available_action', $action)
        ->assertJsonPath('data.attendance_actions.complete', false)
        ->json('data.attendance_actions');
    expect($state['refresh_after_seconds'])->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(60);

    $this->withToken($token)->postJson('/api/attendance/clock-in', ATTENDANCE_VALID_GPS)
        ->assertStatus($action === 'clock_in' ? 201 : 422);
    $this->withToken($token)->postJson('/api/attendance/clock-out', ATTENDANCE_VALID_GPS)
        ->assertStatus($action === 'clock_out' ? 200 : 422);
})->with([
    'start of day' => ['00:00:00', 'clock_in'],
    'early morning' => ['05:00:00', 'clock_in'],
    'before work starts' => ['07:59:59', 'clock_in'],
    'morning start' => ['08:00:00', 'clock_in'],
    'morning end' => ['12:00:00', 'clock_in'],
    'after morning' => ['12:00:01', null],
    'lunch' => ['12:59:59', null],
    'afternoon start' => ['13:00:00', 'clock_out'],
    'after office hours' => ['20:00:00', 'clock_out'],
    'end of day' => ['23:59:59', 'clock_out'],
]);

test('afternoon attendance can be recorded independently through manual qr or proxy attendance', function (string $channel) {
    Carbon::setTestNow('2026-05-05 13:00:00');
    attendanceCompanySettings();
    [$user, $employee] = attendanceEmployeeUser();
    $token = $user->createToken('employee-device')->plainTextToken;
    $payload = ATTENDANCE_VALID_GPS;
    $endpoint = '/api/attendance/clock-out';
    if ($channel === 'qr') {
        $qr = app(AttendanceService::class)->generateQrToken($user);
        $endpoint = '/api/attendance/qr/scan';
        $payload['token'] = $qr->token;
    } elseif ($channel === 'proxy') {
        [$hr] = attendanceEmployeeUser('hr');
        $token = $hr->createToken('hr-device')->plainTextToken;
        auth()->forgetGuards();
        $endpoint = '/api/attendance/proxy-clock-out';
        $payload = ['employee_id' => $employee->id, 'attendance_date' => '2026-05-05'];
    }
    $this->withToken($token)->postJson($endpoint, $payload)->assertSuccessful();
    $this->withToken($token)->postJson($endpoint, $payload)->assertUnprocessable();
    $record = $employee->attendances()->sole();
    expect($record->clock_in_time)->toBeNull()
        ->and($record->clock_out_time)->not->toBeNull()
        ->and($record->status)->toBe('missing_clock_in')
        ->and($record->absent_periods)->toBe([])
        ->and(app(AttendanceService::class)->recordedAttendanceDays($record))->toBe(0.5);
})->with(['manual', 'qr', 'proxy']);

test('approved half day leave completes attendance with only the other shift marker', function (string $period, string $time, string $action) {
    Carbon::setTestNow('2026-05-05 '.$time);
    attendanceCompanySettings();
    [$user, $employee] = attendanceEmployeeUser();
    LeaveRequest::query()->create([
        'employee_id' => $employee->id, 'leave_type' => 'annual',
        'start_date' => '2026-05-05', 'end_date' => '2026-05-05',
        'duration_type' => 'half_day', 'half_day_period' => $period, 'total_days' => 0.5,
        'reason' => 'Approved half day', 'status' => 'approved',
        'hr_approval_status' => 'approved', 'ceo_approval_status' => 'approved',
    ]);
    $token = $user->createToken('employee-device')->plainTextToken;
    $this->withToken($token)->getJson('/api/attendance/summary')
        ->assertJsonPath('data.attendance_actions.available_action', $action);
    $this->withToken($token)->postJson('/api/attendance/'.str_replace('_', '-', $action), ATTENDANCE_VALID_GPS)->assertSuccessful();
    $this->withToken($token)->getJson('/api/attendance/summary')
        ->assertJsonPath('data.attendance_actions.available_action', null)
        ->assertJsonPath('data.attendance_actions.complete', true)
        ->assertJsonPath('data.summary.attended_days', 0.5);
    Carbon::setTestNow('2026-05-05 23:00:00');
    $this->artisan('attendance:mark-missing-clock-out')->assertSuccessful();
    $this->withToken($token)->getJson('/api/attendance/summary')->assertJsonPath('data.summary.missing_clock_out', 0);
    expect($employee->attendances()->sole()->absent_periods)->toBe([]);
})->with([
    'morning leave' => ['morning', '13:00:00', 'clock_out'],
    'afternoon leave' => ['afternoon', '08:00:00', 'clock_in'],
]);

test('approved leave blocks its own shift for manual qr and proxy attendance', function (string $period, string $channel) {
    $time = $period === 'morning' ? '08:00:00' : '13:00:00';
    $action = $period === 'morning' ? 'clock_in' : 'clock_out';
    Carbon::setTestNow('2026-05-05 '.$time);
    attendanceCompanySettings();
    [$user, $employee] = attendanceEmployeeUser();
    LeaveRequest::query()->create([
        'employee_id' => $employee->id, 'leave_type' => 'annual',
        'start_date' => '2026-05-05', 'end_date' => '2026-05-05',
        'duration_type' => 'half_day', 'half_day_period' => $period, 'total_days' => 0.5,
        'reason' => 'Approved half day', 'status' => 'approved',
        'hr_approval_status' => 'approved', 'ceo_approval_status' => 'approved',
    ]);
    $token = $user->createToken('employee-device')->plainTextToken;
    $this->withToken($token)->getJson('/api/attendance/summary')->assertJsonPath('data.attendance_actions.available_action', null);
    $payload = ATTENDANCE_VALID_GPS;
    $endpoint = '/api/attendance/'.str_replace('_', '-', $action);
    if ($channel === 'qr') {
        $payload['token'] = app(AttendanceService::class)->generateQrToken($user)->token;
        $endpoint = '/api/attendance/qr/scan';
    } elseif ($channel === 'proxy') {
        [$hr] = attendanceEmployeeUser('hr');
        $token = $hr->createToken('hr-device')->plainTextToken;
        auth()->forgetGuards();
        $endpoint = '/api/attendance/proxy-'.str_replace('_', '-', $action);
        $payload = ['employee_id' => $employee->id, 'attendance_date' => '2026-05-05'];
    }
    $this->withToken($token)->postJson($endpoint, $payload)->assertUnprocessable();
    expect($employee->attendances()->count())->toBe(0);
})->with(['morning', 'afternoon'])->with(['manual', 'qr', 'proxy']);

test('attendance actions respect configured schedule permissions and date rollover', function () {
    Carbon::setTestNow('2026-05-05 08:00:00');
    attendanceCompanySettings(['working_start_time' => '09:00:00']);
    config(['hr.leave.half_day_schedule.morning_end_time' => '11:30:00', 'hr.leave.half_day_schedule.afternoon_start_time' => '14:00:00']);
    [$user, $employee] = attendanceEmployeeUser();
    $service = app(AttendanceService::class);
    expect($service->attendanceActions($user)['available_action'])->toBe('clock_in');
    Carbon::setTestNow('2026-05-05 09:00:00');
    expect($service->attendanceActions($user)['available_action'])->toBe('clock_in');
    Carbon::setTestNow('2026-05-05 11:30:01');
    expect($service->attendanceActions($user)['available_action'])->toBeNull();
    Carbon::setTestNow('2026-05-05 13:59:59');
    expect($service->attendanceActions($user)['refresh_after_seconds'])->toBe(1);
    Carbon::setTestNow('2026-05-05 14:00:00');
    $service->clockOut($user, ...array_values(ATTENDANCE_VALID_GPS));
    Carbon::setTestNow('2026-05-06 09:00:00');
    expect($service->attendanceActions($user)['available_action'])->toBe('clock_in');
    $user->removeRole('employee');
    expect($service->attendanceActions($user)['available_action'])->toBeNull();
});

test('full day and complementary approved leaves disable both actions', function (bool $complementary) {
    Carbon::setTestNow('2026-05-05 08:00:00');
    attendanceCompanySettings();
    [$user, $employee] = attendanceEmployeeUser();
    foreach ($complementary ? ['morning', 'afternoon'] : [null] as $period) {
        LeaveRequest::query()->create([
            'employee_id' => $employee->id, 'leave_type' => 'annual',
            'start_date' => '2026-05-05', 'end_date' => '2026-05-05',
            'duration_type' => $period ? 'half_day' : 'full_day', 'half_day_period' => $period,
            'total_days' => $period ? 0.5 : 1, 'reason' => 'Approved leave',
            'status' => 'approved', 'hr_approval_status' => 'approved', 'ceo_approval_status' => 'approved',
        ]);
    }
    foreach (['08:00:00', '13:00:00'] as $time) {
        Carbon::setTestNow('2026-05-05 '.$time);
        $state = app(AttendanceService::class)->attendanceActions($user);
        expect($state['available_action'])->toBeNull()->and($state['complete'])->toBeTrue();
    }
})->with([false, true]);

test('proxy morning attendance can fill the other shift after afternoon attendance was recorded', function () {
    Carbon::setTestNow('2026-05-05 13:00:00');
    attendanceCompanySettings();
    [$user, $employee] = attendanceEmployeeUser();
    [$hr] = attendanceEmployeeUser('hr');
    $service = app(AttendanceService::class);
    $service->clockOut($user, ...array_values(ATTENDANCE_VALID_GPS));
    $record = $service->proxyClockIn($hr, $employee->id, '2026-05-05');
    expect($record->clock_in_time)->not->toBeNull()
        ->and($record->clock_out_time)->not->toBeNull()
        ->and($record->absent_periods)->toBe([])
        ->and($service->recordedAttendanceDays($record))->toBe(1.0);
});

test('qr scans do not record a shift outside attendance windows', function (string $time) {
    Carbon::setTestNow('2026-05-05 '.$time);
    attendanceCompanySettings();
    [$user] = attendanceEmployeeUser();
    $qr = app(AttendanceService::class)->generateQrToken($user);
    $token = $user->createToken('employee-device')->plainTextToken;
    $this->withToken($token)->postJson('/api/attendance/qr/scan', ['token' => $qr->token, ...ATTENDANCE_VALID_GPS])->assertUnprocessable();
    expect(Attendance::query()->count())->toBe(0);
})->with(['12:00:01', '12:59:59']);

test('backend attendance actions are unavailable on holidays and non working days', function (bool $holiday) {
    attendanceCompanySettings();
    [$user] = attendanceEmployeeUser();
    Carbon::setTestNow($holiday ? '2026-05-05 13:00:00' : '2026-05-03 13:00:00');
    if ($holiday) {
        PublicHoliday::query()->create(['name' => 'Holiday', 'holiday_date' => '2026-05-05', 'status' => 'active']);
    }
    $state = app(AttendanceService::class)->attendanceActions($user);
    expect($state['available_action'])->toBeNull();
    $token = $user->createToken('employee-device')->plainTextToken;
    $this->withToken($token)->postJson('/api/attendance/clock-out', ATTENDANCE_VALID_GPS)->assertUnprocessable();
    expect(Attendance::query()->count())->toBe(0);
})->with([true, false]);

test('hr can proxy attendance using the numeric id returned by employee search', function (string $action) {
    Carbon::setTestNow('2026-05-05 13:00:00');
    attendanceCompanySettings();
    [$hr] = attendanceEmployeeUser('hr');
    [, $employee] = attendanceEmployeeUser('employee', [], ['employee_id' => 'EMP-PROXY-LOOKUP', 'full_name' => 'Proxy Lookup Employee']);
    $token = $hr->createToken('hr-device')->plainTextToken;

    $option = $this->withToken($token)->getJson('/api/employees/search?q=EMP-PROXY-LOOKUP')
        ->assertSuccessful()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $employee->id)
        ->assertJsonPath('0.employee_id', 'EMP-PROXY-LOOKUP')
        ->json('0');
    expect($option['id'])->toBeInt();

    $this->withToken($token)->postJson('/api/attendance/proxy-'.$action, [
        'employee_id' => $option['id'],
        'attendance_date' => '2026-05-05',
    ])->assertSuccessful()->assertJsonPath('data.employee.employee_id', 'EMP-PROXY-LOOKUP');

    $record = $employee->attendances()->sole();
    expect($action === 'clock-in' ? $record->clock_in_time : $record->clock_out_time)->not->toBeNull();
})->with(['clock-in', 'clock-out']);

test('partial attendance corrections validate against retained clock times', function (array $changes) {
    Carbon::setTestNow('2026-05-05 18:00:00');
    attendanceCompanySettings();
    [$hr] = attendanceEmployeeUser('hr');
    [, $employee] = attendanceEmployeeUser();
    $attendance = Attendance::query()->create([
        'employee_id' => $employee->id, 'attendance_date' => '2026-05-05',
        'clock_in_time' => '2026-05-05 08:00:00', 'clock_out_time' => '2026-05-05 17:00:00',
        'status' => 'present', 'is_late' => false,
    ]);
    $token = $hr->createToken('attendance-correction-test')->plainTextToken;
    $this->withToken($token)->putJson("/api/attendance/{$attendance->id}/correction", [
        ...$changes, 'correction_reason' => 'Verify retained clock times.',
    ])->assertUnprocessable()->assertJsonValidationErrors('clock_out_time');
    expect($attendance->fresh()->clock_in_time->format('H:i:s'))->toBe('08:00:00')
        ->and($attendance->fresh()->clock_out_time->format('H:i:s'))->toBe('17:00:00');
})->with([
    'clock-in after existing clock-out' => [['clock_in_time' => '2026-05-05 18:00:00']],
    'clock-out before existing clock-in' => [['clock_out_time' => '2026-05-05 07:00:00']],
]);

test('attendance correction can explicitly clear a mistaken clock timestamp', function (string $field) {
    Carbon::setTestNow('2026-05-05 18:00:00');
    attendanceCompanySettings();
    [$hr] = attendanceEmployeeUser('hr');
    [, $employee] = attendanceEmployeeUser();
    $attendance = Attendance::query()->create([
        'employee_id' => $employee->id, 'attendance_date' => '2026-05-05',
        'clock_in_time' => '2026-05-05 08:00:00', 'clock_out_time' => '2026-05-05 17:00:00',
        'status' => 'present', 'is_late' => false,
    ]);
    $token = $hr->createToken('attendance-correction-test')->plainTextToken;
    $this->withToken($token)->putJson("/api/attendance/{$attendance->id}/correction", [
        $field => null, 'correction_reason' => 'Remove an incorrectly recorded timestamp.',
    ])->assertOk()->assertJsonPath("data.{$field}", null);
    expect($attendance->fresh()->getAttribute($field))->toBeNull();
    $audit = Activity::query()->where('log_name', 'attendance')->where('event', 'correction')->sole();
    expect($audit->properties['old_values'][$field])->not->toBeNull()
        ->and($audit->properties['new_values'][$field])->toBeNull();
})->with(['clock_in_time', 'clock_out_time']);

test('automatic attendance stages preserve both missing issues until the final cutoff', function () {
    Carbon::setTestNow('2026-05-05 09:29:59');
    attendanceCompanySettings();
    [$user, $employee] = attendanceEmployeeUser('employee', [], ['join_date' => '2026-05-05']);
    $service = app(AttendanceService::class);
    $service->checkMissingClockIn();
    expect($employee->attendances()->count())->toBe(0);

    Carbon::setTestNow('2026-05-05 09:30:00');
    $service->checkMissingClockIn();
    $record = $employee->attendances()->sole();
    expect($record->status)->toBe('missing_clock_in')->and($record->absent_periods)->toBe([]);
    $service->checkMissingClockIn();
    expect($employee->attendances()->count())->toBe(1);

    Carbon::setTestNow('2026-05-05 17:29:59');
    $service->markMissingClockOut(scheduledFollowUp: true);
    expect($record->fresh()->status)->toBe('missing_clock_in');
    Carbon::setTestNow('2026-05-05 17:30:00');
    $service->markMissingClockOut(scheduledFollowUp: true);
    expect($record->fresh()->status)->toBe('missing_both')->and($record->fresh()->absent_periods)->toBe([]);
    $summary = $service->summary($user);
    expect($summary['summary']['absent'])->toBe(0.0)
        ->and($summary['summary']['missing_clock_in'])->toBe(1)
        ->and($summary['summary']['missing_clock_out'])->toBe(1);

    Carbon::setTestNow('2026-05-05 22:59:59');
    $service->markAbsent();
    expect($record->fresh()->status)->toBe('missing_both');
    Carbon::setTestNow('2026-05-05 23:00:00');
    $service->markAbsent();
    expect($record->fresh()->status)->toBe('absent')->and($record->fresh()->absent_periods)->toBe(['morning', 'afternoon']);
    $service->checkMissingClockIn();
    $service->markMissingClockOut(scheduledFollowUp: true);
    $service->markAbsent();
    expect($record->fresh()->status)->toBe('absent')->and($employee->attendances()->count())->toBe(1);
    Carbon::setTestNow('2026-05-06 09:30:00');
    $service->checkMissingClockIn('2026-05-05');
    $service->markMissingClockOut('2026-05-05', true);
    expect($record->fresh()->status)->toBe('absent');
});

test('final attendance check marks only unresolved halves', function (bool $morning, bool $afternoon, array $expected) {
    Carbon::setTestNow('2026-05-05 17:30:00');
    attendanceCompanySettings();
    [, $employee] = attendanceEmployeeUser();
    $record = Attendance::query()->create([
        'employee_id' => $employee->id, 'attendance_date' => '2026-05-05',
        'clock_in_time' => $morning ? '2026-05-05 08:30:00' : null,
        'clock_out_time' => $afternoon ? '2026-05-05 17:00:00' : null,
        'status' => $morning ? 'late' : 'missing_clock_in', 'is_late' => $morning,
    ]);
    $service = app(AttendanceService::class);
    $service->markMissingClockOut(scheduledFollowUp: true);
    expect($record->fresh()->status)->not->toBe('absent');
    Carbon::setTestNow('2026-05-05 23:00:00');
    $service->markAbsent();
    expect($record->fresh()->status)->toBe($expected === [] ? 'late' : 'absent');
    if ($expected !== []) {
        expect($record->fresh()->absent_periods)->toBe($expected);
    }
    expect($record->fresh()->is_late)->toBe($morning);
})->with([
    'morning only' => [true, false, ['afternoon']],
    'afternoon only' => [false, true, ['morning']],
    'both attended' => [true, true, []],
]);

test('later clock out clears the afternoon flag without hiding the missing morning', function () {
    Carbon::setTestNow('2026-05-05 17:30:00');
    attendanceCompanySettings();
    [$user, $employee] = attendanceEmployeeUser();
    $service = app(AttendanceService::class);
    $service->markMissingClockOut(scheduledFollowUp: true);
    $record = $employee->attendances()->sole();
    expect($record->status)->toBe('missing_both');
    $service->clockOut($user, ...array_values(ATTENDANCE_VALID_GPS));
    expect($record->fresh()->status)->toBe('missing_clock_in')->and($record->fresh()->absent_periods)->toBe([]);
    Carbon::setTestNow('2026-05-05 23:00:00');
    $service->markAbsent();
    expect($record->fresh()->absent_periods)->toBe(['morning']);
});

test('approved leave clears only its provisional missing issue', function (string $period, string $expected) {
    Carbon::setTestNow('2026-05-05 17:30:00');
    attendanceCompanySettings();
    [, $employee] = attendanceEmployeeUser();
    $service = app(AttendanceService::class);
    $service->markMissingClockOut(scheduledFollowUp: true);
    LeaveRequest::query()->create([
        'employee_id' => $employee->id, 'leave_type' => 'annual',
        'start_date' => '2026-05-05', 'end_date' => '2026-05-05',
        'duration_type' => 'half_day', 'half_day_period' => $period,
        'total_days' => 0.5, 'reason' => 'Approved later', 'status' => 'approved',
    ]);
    $service->reconcileApprovedLeave($employee->id, today(), today());
    expect($employee->attendances()->sole()->status)->toBe($expected)
        ->and($employee->attendances()->sole()->absent_periods)->toBe([]);
    Carbon::setTestNow('2026-05-05 23:00:00');
    $service->markAbsent();
    expect($employee->attendances()->sole()->absent_periods)->toBe([$period === 'morning' ? 'afternoon' : 'morning']);
})->with([
    'morning leave' => ['morning', 'missing_clock_out'],
    'afternoon leave' => ['afternoon', 'missing_clock_in'],
]);

test('automatic sweeps preserve early HR absence with a reason', function () {
    Carbon::setTestNow('2026-05-05 09:30:00');
    attendanceCompanySettings();
    [$hr, $employee] = attendanceEmployeeUser('hr');
    $service = app(AttendanceService::class);
    $service->checkMissingClockIn();
    $record = $employee->attendances()->sole();
    $service->correct($record, ['status' => 'absent', 'correction_reason' => 'Confirmed absence with employee'], $hr);
    $original = $record->fresh()->only(['status', 'absent_periods', 'corrected_at', 'correction_reason']);
    Carbon::setTestNow('2026-05-05 17:30:00');
    $service->markMissingClockOut(scheduledFollowUp: true);
    Carbon::setTestNow('2026-05-05 23:00:00');
    $service->markAbsent();
    expect($record->fresh()->only(array_keys($original)))->toEqual($original);
});

test('needs review filter includes each missing status and summaries count both issues', function () {
    Carbon::setTestNow('2026-05-05 17:30:00');
    attendanceCompanySettings();
    [$hr] = attendanceEmployeeUser('hr', [], ['join_date' => '2026-05-05']);
    foreach (['missing_clock_in', 'missing_clock_out', 'missing_both'] as $status) {
        [, $employee] = attendanceEmployeeUser('employee', [], ['join_date' => '2026-05-05']);
        Attendance::query()->create(['employee_id' => $employee->id, 'attendance_date' => today(), 'status' => $status, 'absent_periods' => []]);
    }
    $this->actingAs($hr)->getJson('/api/attendance?status=needs_review')->assertSuccessful()->assertJsonPath('meta.total', 3);
    $this->actingAs($hr)->getJson('/api/attendance/team-summary?month=5&year=2026')->assertSuccessful()
        ->assertJsonPath('data.summary.missing_clock_in', 2)->assertJsonPath('data.summary.missing_clock_out', 2)
        ->assertJsonPath('data.summary.absent', 0);
});

test('late evening attendance can clear a finalized half-day absence', function () {
    Carbon::setTestNow('2026-05-05 23:00:00');
    attendanceCompanySettings();
    [$user, $employee] = attendanceEmployeeUser();
    $service = app(AttendanceService::class);
    $service->markAbsent();
    $record = $employee->attendances()->sole();
    Carbon::setTestNow('2026-05-05 23:10:00');
    $service->clockOut($user, ...array_values(ATTENDANCE_VALID_GPS));
    expect($record->fresh()->status)->toBe('absent')->and($record->fresh()->absent_periods)->toBe(['morning']);
});

test('afternoon sweep respects half-day leave', function () {
    Carbon::setTestNow('2026-05-05 17:30:00');
    attendanceCompanySettings();
    foreach (['morning' => 'missing_clock_out', 'afternoon' => 'missing_clock_in'] as $period => $expected) {
        [, $employee] = attendanceEmployeeUser();
        LeaveRequest::query()->create([
            'employee_id' => $employee->id, 'leave_type' => 'annual',
            'start_date' => today(), 'end_date' => today(), 'duration_type' => 'half_day',
            'half_day_period' => $period, 'total_days' => 0.5, 'reason' => 'Approved leave', 'status' => 'approved',
        ]);
        app(AttendanceService::class)->markMissingClockOut(scheduledFollowUp: true);
        expect($employee->attendances()->sole()->status)->toBe($expected)
            ->and($employee->attendances()->sole()->absent_periods)->toBe([]);
    }
});

test('missing status migration refuses a rollback that would lose review records', function () {
    [, $employee] = attendanceEmployeeUser();
    $record = Attendance::query()->create([
        'employee_id' => $employee->id, 'attendance_date' => today(), 'status' => 'missing_both',
    ]);
    $migration = require database_path('migrations/2026_10_20_000003_add_missing_attendance_statuses.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    expect($record->fresh()->status)->toBe('missing_both');
});

test('missing status migration preserves historical attendance through a safe rollback and upgrade', function () {
    [, $employee] = attendanceEmployeeUser();
    $record = Attendance::query()->create([
        'employee_id' => $employee->id, 'attendance_date' => '2026-05-05',
        'status' => 'absent', 'absent_periods' => ['morning'],
    ]);
    $migration = require database_path('migrations/2026_10_20_000003_add_missing_attendance_statuses.php');
    try {
        $migration->down();
        expect($record->fresh()->status)->toBe('absent')->and($record->fresh()->absent_periods)->toBe(['morning']);
    } finally {
        $migration->up();
    }
    expect($record->fresh()->status)->toBe('absent')->and($record->fresh()->absent_periods)->toBe(['morning']);
    $record->update(['status' => 'missing_clock_in', 'absent_periods' => []]);
    expect($record->fresh()->status)->toBe('missing_clock_in');
});
