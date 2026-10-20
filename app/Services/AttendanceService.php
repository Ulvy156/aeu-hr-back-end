<?php

namespace App\Services;

use App\Enums\Status;
use App\Exceptions\ApiException;
use App\Models\Attendance;
use App\Models\AttendanceQrToken;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PublicHoliday;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class AttendanceService
{
    public function __construct(
        protected AuditLogService $auditLogService,
        protected CompanySettingService $companySettingService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, User $viewer): LengthAwarePaginator
    {
        $filters['employee_id'] = Employee::resolveId($filters['employee_id'] ?? null);
        $perPage = (int) ($filters['per_page'] ?? 15);

        $query = Attendance::query()
            ->with(['employee', 'correctedBy', 'proxiedClockInBy', 'proxiedClockOutBy'])
            ->when($filters['attendance_date'] ?? null, fn (Builder $query, string $attendanceDate) => $query->whereDate('attendance_date', $attendanceDate))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $dateFrom) => $query->whereDate('attendance_date', '>=', $dateFrom))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $dateTo) => $query->whereDate('attendance_date', '<=', $dateTo))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $status === 'late'
                ? $query->where(fn (Builder $lateQuery) => $lateQuery->where('status', 'late')->orWhere('is_late', true))
                : $query->where('status', $status))
            ->orderByDesc('attendance_date')
            ->orderByDesc('id');

        if (($filters['scope'] ?? null) === 'own') {
            if (! $viewer->hasPermissionTo('attendance.view_own')) {
                throw ApiException::forbidden();
            }

            $query->whereBelongsTo($this->employeeForUserOrFail($viewer));
        } elseif ($viewer->hasPermissionTo('attendance.view_any')) {
            $query->when($filters['employee_id'] ?? null, fn (Builder $query, int $employeeId) => $query->where('employee_id', $employeeId));
            $query->when($filters['employee_name'] ?? null, fn (Builder $query, string $name) => $query->whereHas('employee', fn (Builder $employeeQuery) => $employeeQuery->whereRaw('LOWER(full_name) LIKE ?', ['%'.mb_strtolower(trim($name)).'%'])));
            $query->when($filters['department_id'] ?? null, fn (Builder $query, int $departmentId) => $query->whereHas('employee', fn (Builder $employeeQuery) => $employeeQuery->where('department_id', $departmentId)));
        } elseif ($viewer->hasPermissionTo('attendance.view_own')) {
            if (($filters['scope'] ?? null) === 'team') {
                throw ApiException::forbidden();
            }

            $employee = $this->employeeForUserOrFail($viewer);

            $query->whereBelongsTo($employee);
        } else {
            throw ApiException::forbidden();
        }

        return $query->paginate($perPage);
    }

    public function clockIn(User $user, float $latitude, float $longitude): Attendance
    {
        $employee = $this->employeeForUserOrFail($user);
        $settings = $this->companySettingService->current();

        $this->assertOfficeGpsConfigured($settings);
        $this->assertWithinAllowedLocation($latitude, $longitude, $settings, 'clock-in');

        $clockInAt = now();
        $attendanceDate = $clockInAt->toDateString();
        $this->assertAttendanceWindow('clock_in', $clockInAt, $settings);

        $this->assertLeaveAllowsAttendance($employee, $attendanceDate, 'clock_in', 'You are on approved leave today and cannot clock in.');

        if ($this->isPublicHoliday($clockInAt)) {
            throw ApiException::unprocessable('Today is a public holiday and attendance is not required.');
        }

        if (! $this->isWorkingDay($clockInAt, $settings)) {
            throw ApiException::unprocessable('Today is not a working day.');
        }

        return $this->recordClockIn($employee, $attendanceDate, [
            'clock_in_time' => $clockInAt,
            'clock_in_latitude' => $latitude,
            'clock_in_longitude' => $longitude,
            'status' => $this->isLateForEmployee($clockInAt, $employee, $settings) ? 'late' : 'present',
            'is_late' => $this->isLateForEmployee($clockInAt, $employee, $settings),
        ], 'You have already clocked in today.');
    }

    public function clockOut(User $user, float $latitude, float $longitude): Attendance
    {
        $employee = $this->employeeForUserOrFail($user);
        $settings = $this->companySettingService->current();

        $this->assertOfficeGpsConfigured($settings);
        $this->assertWithinAllowedLocation($latitude, $longitude, $settings, 'clock-out');

        $attendanceDate = now()->toDateString();

        $clockOutAt = now();
        $this->assertAttendanceWindow('clock_out', $clockOutAt, $settings);
        $this->assertLeaveAllowsAttendance($employee, $attendanceDate, 'clock_out', 'You are on approved leave today and cannot clock out.');

        if ($this->isPublicHoliday(now())) {
            throw ApiException::unprocessable('Today is a public holiday and attendance is not required.');
        }

        if (! $this->isWorkingDay(now(), $settings)) {
            throw ApiException::unprocessable('Today is not a working day.');
        }

        return $this->recordClockOut($employee, $attendanceDate, [
            'clock_out_time' => $clockOutAt,
            'clock_out_latitude' => $latitude,
            'clock_out_longitude' => $longitude,
        ], 'You have already clocked out today.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function correct(
        Attendance $attendance,
        array $data,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Attendance {
        return DB::transaction(function () use ($attendance, $data, $actor, $ipAddress, $userAgent): Attendance {
            Employee::query()->whereKey($attendance->employee_id)->lockForUpdate()->firstOrFail();
            $attendance = Attendance::query()->whereKey($attendance->id)->lockForUpdate()->firstOrFail();
            $settings = $this->companySettingService->current();
            $attendance->loadMissing(['employee', 'correctedBy']);
            $oldValues = $this->auditAttributes($attendance);

            $attributes = [
                'correction_reason' => $data['correction_reason'],
                'corrected_by' => $actor->id,
                'corrected_at' => now(),
            ];

            if (array_key_exists('clock_in_time', $data)) {
                $attributes['clock_in_time'] = $data['clock_in_time'];
            }

            if (array_key_exists('clock_out_time', $data)) {
                $attributes['clock_out_time'] = $data['clock_out_time'];
            }

            if (array_key_exists('status', $data)) {
                $attributes['status'] = $data['status'];
            }

            $finalClockInTime = array_key_exists('clock_in_time', $attributes)
                ? ($attributes['clock_in_time'] ? Carbon::parse((string) $attributes['clock_in_time']) : null)
                : $attendance->clock_in_time;
            $finalStatus = (string) ($attributes['status'] ?? $attendance->status);
            $finalClockOutTime = array_key_exists('clock_out_time', $attributes)
                ? ($attributes['clock_out_time'] ? Carbon::parse((string) $attributes['clock_out_time']) : null)
                : $attendance->clock_out_time;

            if ($finalClockInTime && $finalClockOutTime && $finalClockOutTime->lt($finalClockInTime)) {
                $message = 'The clock out time must be after or equal to the clock in time.';
                throw ApiException::unprocessable($message, ['clock_out_time' => [$message]]);
            }

            if ($finalStatus === 'absent') {
                $attributes['absent_periods'] = $this->absentPeriodsForAttendance(
                    Attendance::make([
                        'attendance_date' => $attendance->attendance_date,
                        'clock_in_time' => $finalClockInTime,
                        'clock_out_time' => $finalClockOutTime,
                    ]),
                    $attendance->attendance_date,
                    $settings,
                    $this->approvedLeavePeriods($attendance->employee_id, $attendance->attendance_date->toDateString()),
                );
                if ($attributes['absent_periods'] === [] && $finalClockInTime) {
                    $finalStatus = $this->isLateForTime($finalClockInTime, 'present', $settings) ? 'late' : 'present';
                    $attributes['status'] = $finalStatus;
                }
            } else {
                $attributes['absent_periods'] = [];
            }

            $attributes['is_late'] = $finalStatus === 'late'
                || $this->isLateForTime($finalClockInTime, $finalStatus, $settings);

            $attendance->update($attributes);
            $attendance = $attendance->fresh(['employee', 'correctedBy', 'proxiedClockInBy', 'proxiedClockOutBy']);

            $this->auditLogService->log(
                action: 'correction',
                module: 'attendance',
                user: $actor,
                subject: $attendance,
                oldValues: $oldValues,
                newValues: $this->auditAttributes($attendance),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $attendance;
        });
    }

    /** Reconcile recorded absent periods when retroactive leave is approved. */
    public function reconcileApprovedLeave(int $employeeId, CarbonInterface $startDate, CarbonInterface $endDate): int
    {
        $updated = 0;
        $records = Attendance::query()
            ->where('employee_id', $employeeId)
            ->where('status', 'absent')
            ->whereDate('attendance_date', '>=', $startDate->toDateString())
            ->whereDate('attendance_date', '<=', $endDate->toDateString())
            ->get();

        foreach ($records as $record) {
            $periods = $record->absent_periods ?? ['morning', 'afternoon'];
            $leavePeriods = $this->approvedLeavePeriods($employeeId, $record->attendance_date->toDateString());
            $remaining = array_values(array_diff($periods, $leavePeriods));

            if ($remaining === []) {
                if ($record->clock_in_time) {
                    $record->update([
                        'status' => $this->isLateForTime($record->clock_in_time, 'present', $this->companySettingService->current()) ? 'late' : 'present',
                        'absent_periods' => [],
                    ]);
                } else {
                    $record->delete();
                }
            } else {
                $record->update(['absent_periods' => $remaining]);
            }
            $updated++;
        }

        return $updated;
    }

    /**
     * @return array{attendance_date: string, created_count: int, updated_count: int}
     */
    public function markAbsent(?string $attendanceDate = null): array
    {
        $date = $attendanceDate ? Carbon::parse($attendanceDate)->startOfDay() : today();
        $settings = $this->companySettingService->current();

        if (! $this->isWorkingDay($date, $settings) || $this->isPublicHoliday($date)) {
            return [
                'attendance_date' => $date->toDateString(),
                'created_count' => 0,
                'updated_count' => 0,
            ];
        }

        [$createdCount, $updatedCount] = DB::transaction(function () use ($date, $settings): array {
            $employees = Employee::query()
                ->whereDate('join_date', '<=', $date->toDateString())
                ->where(function (Builder $query) use ($date): void {
                    $query
                        ->whereNull('last_working_date')
                        ->orWhereDate('last_working_date', '>=', $date->toDateString());
                })
                ->get(['id']);
            $employeeIds = $employees->modelKeys();
            $attendanceByEmployee = Attendance::query()
                ->whereIn('employee_id', $employeeIds)
                ->whereDate('attendance_date', $date->toDateString())
                ->lockForUpdate()
                ->get()
                ->keyBy('employee_id');
            $leaveByEmployee = LeaveRequest::query()
                ->whereIn('employee_id', $employeeIds)
                ->where('status', 'approved')
                ->whereDate('start_date', '<=', $date->toDateString())
                ->whereDate('end_date', '>=', $date->toDateString())
                ->get()
                ->groupBy('employee_id');
            $created = 0;
            $updated = 0;

            foreach ($employees as $employee) {
                $attendance = $attendanceByEmployee->get($employee->id);
                if ($attendance?->corrected_at) {
                    continue;
                }

                $leavePeriods = $this->approvedLeavePeriodsFromCollection($leaveByEmployee->get($employee->id, collect()));
                $absentPeriods = $this->absentPeriodsForAttendance($attendance, $date, $settings, $leavePeriods);

                if ($absentPeriods === []) {
                    if ($attendance?->status === 'absent') {
                        if ($attendance->clock_in_time) {
                            $attendance->update([
                                'status' => $this->isLateForTime($attendance->clock_in_time, 'present', $settings) ? 'late' : 'present',
                                'absent_periods' => [],
                            ]);
                        } else {
                            $attendance->delete();
                        }
                        $updated++;
                    }

                    continue;
                }

                if ($attendance) {
                    $attendance->update(['status' => 'absent', 'absent_periods' => $absentPeriods]);
                    $updated++;
                } else {
                    Attendance::query()->create([
                        'employee_id' => $employee->id,
                        'attendance_date' => $date->toDateString(),
                        'status' => 'absent',
                        'absent_periods' => $absentPeriods,
                        'is_late' => false,
                    ]);
                    $created++;
                }
            }

            return [$created, $updated];
        });

        return [
            'attendance_date' => $date->toDateString(),
            'created_count' => $createdCount,
            'updated_count' => $updatedCount,
        ];
    }

    /**
     * Flag employees expected in the morning who have not clocked in.
     *
     * @return array{attendance_date: string, updated_count: int}
     */
    public function checkMissingClockIn(?string $attendanceDate = null): array
    {
        $date = $attendanceDate ? Carbon::parse($attendanceDate)->startOfDay() : today();
        $settings = $this->companySettingService->current();

        if (! $this->isWorkingDay($date, $settings) || $this->isPublicHoliday($date)) {
            return ['attendance_date' => $date->toDateString(), 'updated_count' => 0];
        }

        $updatedCount = DB::transaction(function () use ($date): int {
            $employees = Employee::query()
                ->whereDate('join_date', '<=', $date->toDateString())
                ->where(fn (Builder $query) => $query->whereNull('last_working_date')->orWhereDate('last_working_date', '>=', $date->toDateString()))
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
            $employeeIds = $employees->modelKeys();
            $attendances = Attendance::query()
                ->whereIn('employee_id', $employeeIds)
                ->whereDate('attendance_date', $date->toDateString())
                ->lockForUpdate()
                ->get()
                ->keyBy('employee_id');
            $leaves = LeaveRequest::query()
                ->whereIn('employee_id', $employeeIds)
                ->where('status', 'approved')
                ->whereDate('start_date', '<=', $date->toDateString())
                ->whereDate('end_date', '>=', $date->toDateString())
                ->get()
                ->groupBy('employee_id');

            $updated = 0;
            foreach ($employees as $employee) {
                $leavePeriods = $this->approvedLeavePeriodsFromCollection($leaves->get($employee->id, collect()));
                if (in_array('morning', $leavePeriods, true)) {
                    continue;
                }

                $attendance = $attendances->get($employee->id);
                if ($attendance?->clock_in_time || $attendance?->corrected_at) {
                    continue;
                }

                $absentPeriods = array_values(array_unique([...(array) ($attendance?->absent_periods ?? []), 'morning']));
                if ($attendance) {
                    if ($attendance->status === 'absent' && $attendance->absent_periods === $absentPeriods) {
                        continue;
                    }
                    $attendance->update(['status' => 'absent', 'absent_periods' => $absentPeriods]);
                } else {
                    Attendance::query()->create([
                        'employee_id' => $employee->id,
                        'attendance_date' => $date->toDateString(),
                        'status' => 'absent',
                        'absent_periods' => $absentPeriods,
                        'is_late' => false,
                    ]);
                }
                $updated++;
            }

            return $updated;
        });

        return ['attendance_date' => $date->toDateString(), 'updated_count' => $updatedCount];
    }

    /**
     * Flag afternoon attendance issues for HR follow-up. Scheduled runs also flag employees
     * who were expected to work in the afternoon but did not record afternoon attendance.
     *
     * @return array{attendance_date: string|null, updated_count: int}
     */
    public function markMissingClockOut(?string $attendanceDate = null, bool $scheduledFollowUp = false): array
    {
        $settings = $this->companySettingService->current();
        $graceHours = $this->missingClockOutGraceHours();
        $date = $attendanceDate ? Carbon::parse($attendanceDate)->toDateString() : ($scheduledFollowUp ? today()->toDateString() : null);

        $updatedCount = DB::transaction(function () use ($settings, $graceHours, $date, $scheduledFollowUp): int {
            $query = Attendance::query()
                ->whereNotNull('clock_in_time')
                ->whereNull('clock_out_time')
                ->whereIn('status', ['present', 'late'])
                ->when($date, fn (Builder $query) => $query->whereDate('attendance_date', $date))
                ->lockForUpdate()
                ->orderBy('id');

            $updated = 0;

            foreach ($query->get() as $attendance) {
                if (in_array('afternoon', $this->approvedLeavePeriods((int) $attendance->employee_id, (string) $attendance->attendance_date->toDateString()), true)) {
                    continue;
                }

                if (! $scheduledFollowUp && ! $this->hasPassedMissingClockOutDeadline($attendance, $settings, $graceHours)) {
                    continue;
                }

                $attendance->update([
                    'status' => 'missing_clock_out',
                    'is_late' => $attendance->is_late || $attendance->status === 'late',
                ]);
                $updated++;
            }

            if ($scheduledFollowUp && $date && $this->isWorkingDay(Carbon::parse($date), $settings) && ! $this->isPublicHoliday(Carbon::parse($date))) {
                $employees = Employee::query()
                    ->whereDate('join_date', '<=', $date)
                    ->where(fn (Builder $query) => $query->whereNull('last_working_date')->orWhereDate('last_working_date', '>=', $date))
                    ->get(['id']);
                $employeeIds = $employees->modelKeys();
                $attendances = Attendance::query()->whereIn('employee_id', $employeeIds)->whereDate('attendance_date', $date)->lockForUpdate()->get()->keyBy('employee_id');
                $leaves = LeaveRequest::query()->whereIn('employee_id', $employeeIds)->where('status', 'approved')
                    ->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->get()->groupBy('employee_id');

                foreach ($employees as $employee) {
                    $leavePeriods = $this->approvedLeavePeriodsFromCollection($leaves->get($employee->id, collect()));
                    if (in_array('afternoon', $leavePeriods, true)) {
                        continue;
                    }

                    $attendance = $attendances->get($employee->id);
                    if ($attendance?->corrected_at) {
                        continue;
                    }

                    if ($attendance?->clock_in_time && ! $attendance->clock_out_time) {
                        // Open clock-ins were flagged above. Morning leave does not excuse
                        // a missing clock-out for an afternoon shift.
                        if ($attendance->status !== 'missing_clock_out') {
                            $attendance->update([
                                'status' => 'missing_clock_out',
                                'is_late' => $attendance->is_late || $attendance->status === 'late',
                            ]);
                            $updated++;
                        }

                        continue;
                    }

                    $attendedPeriods = $attendance ? $this->attendedPeriodsForAttendance($attendance, $settings) : [];
                    if (in_array('afternoon', $attendedPeriods, true)) {
                        continue;
                    }

                    $absentPeriods = array_values(array_unique([...(array) ($attendance?->absent_periods ?? []), 'afternoon']));
                    if ($attendance) {
                        if ($attendance->status !== 'absent' || $attendance->absent_periods !== $absentPeriods) {
                            $attendance->update(['status' => 'absent', 'absent_periods' => $absentPeriods]);
                            $updated++;
                        }
                    } else {
                        Attendance::query()->create([
                            'employee_id' => $employee->id,
                            'attendance_date' => $date,
                            'status' => 'absent',
                            'absent_periods' => ['afternoon'],
                            'is_late' => false,
                        ]);
                        $updated++;
                    }
                }
            }

            return $updated;
        });

        return [
            'attendance_date' => $date,
            'updated_count' => $updatedCount,
        ];
    }

    public function proxyClockIn(
        User $actor,
        int $employeeId,
        string $attendanceDate,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Attendance {
        $employee = Employee::query()->findOrFail($employeeId);
        $settings = $this->companySettingService->current();

        $date = Carbon::parse($attendanceDate)->startOfDay();
        $clockInTime = Carbon::parse($date->toDateString().' '.$settings->working_start_time);
        $this->assertLeaveAllowsAttendance($employee, $date->toDateString(), 'clock_in', 'This employee is on approved leave on the selected date and cannot be clocked in.');

        $attendance = $this->recordClockIn($employee, $date->toDateString(), [
            'clock_in_time' => $clockInTime,
            'status' => 'present',
            'is_late' => false,
            'proxied_clock_in_by' => $actor->id,
        ], 'An attendance record already exists for this employee on the selected date.');

        $this->auditLogService->log(
            action: 'proxy_clock_in',
            module: 'attendance',
            user: $actor,
            subject: $attendance,
            oldValues: [],
            newValues: $this->auditAttributes($attendance),
            ipAddress: $ipAddress,
            userAgent: $userAgent,
        );

        return $attendance;
    }

    public function proxyClockOut(
        User $actor,
        int $employeeId,
        string $attendanceDate,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Attendance {
        $employee = Employee::query()->findOrFail($employeeId);
        $settings = $this->companySettingService->current();

        $date = Carbon::parse($attendanceDate)->startOfDay();

        $clockOutTime = Carbon::parse($date->toDateString().' '.$settings->working_end_time);
        $this->assertLeaveAllowsAttendance($employee, $date->toDateString(), 'clock_out', 'This employee is on approved leave on the selected date and cannot be clocked out.');

        $attendance = Attendance::query()
            ->whereBelongsTo($employee)
            ->whereDate('attendance_date', $date->toDateString())
            ->first();

        $oldValues = $attendance ? $this->auditAttributes($attendance) : [];
        $attendance = $this->recordClockOut($employee, $date->toDateString(), [
            'clock_out_time' => $clockOutTime,
            'proxied_clock_out_by' => $actor->id,
        ], 'This employee has already clocked out on the selected date.');

        $this->auditLogService->log(
            action: 'proxy_clock_out',
            module: 'attendance',
            user: $actor,
            subject: $attendance,
            oldValues: $oldValues,
            newValues: $this->auditAttributes($attendance),
            ipAddress: $ipAddress,
            userAgent: $userAgent,
        );

        return $attendance;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(User $viewer, array $filters = []): array
    {
        $employee = $this->employeeForUserOrFail($viewer);

        $month = (int) ($filters['month'] ?? now()->month);
        $year = (int) ($filters['year'] ?? now()->year);

        $periodStart = Carbon::create($year, $month, 1)->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();
        $effectiveTo = $periodEnd->greaterThan(today()) ? today() : $periodEnd;

        $this->reconcileMissingClockOutForEmployee($employee->id, $periodStart, $effectiveTo);

        $records = Attendance::query()
            ->whereBelongsTo($employee)
            ->whereDate('attendance_date', '>=', $periodStart->toDateString())
            ->whereDate('attendance_date', '<=', $effectiveTo->toDateString())
            ->get(['employee_id', 'attendance_date', 'status', 'absent_periods', 'is_late', 'clock_in_time', 'clock_out_time']);

        $present = $records->where('status', 'present')->count();
        // A manual late status and a time-based late flag both count as late.
        $late = $records->filter(fn (Attendance $record) => $record->status === 'late' || $record->is_late)->count();
        $absent = ($this->recordedAbsenceDaysByEmployee($records)[$employee->id] ?? 0.0)
            + $this->countUnrecordedAbsences($periodStart, $effectiveTo, $employee->id);
        $missingClockOut = $records->where('status', 'missing_clock_out')->count();
        $attendedDays = $records
            ->sum(fn (Attendance $record): float => $this->recordedAttendanceDays($record, $this->companySettingService->current()));

        $workingDaysCount = $this->countWorkingDays($periodStart, $effectiveTo);

        $attendanceRate = $workingDaysCount > 0
            ? number_format(($attendedDays / $workingDaysCount) * 100, 2)
            : '0.00';

        $isCurrentPeriod = $month === now()->month && $year === now()->year;
        $todayData = null;

        if ($isCurrentPeriod) {
            $todayRecord = $records->first(
                fn (Attendance $a) => $a->attendance_date->toDateString() === today()->toDateString()
            );

            $todayData = $todayRecord ? [
                'status' => $todayRecord->status,
                'clock_in_time' => $todayRecord->clock_in_time?->toISOString(),
                'clock_out_time' => $todayRecord->clock_out_time?->toISOString(),
                'is_late' => $todayRecord->is_late,
            ] : null;
        }

        return [
            'employee' => [
                'id' => $employee->id,
                'employee_id' => $employee->employee_id,
                'full_name' => $employee->full_name,
            ],
            'period' => [
                'month' => $month,
                'year' => $year,
                'from' => $periodStart->toDateString(),
                'to' => $periodEnd->toDateString(),
            ],
            'summary' => [
                'present' => $present,
                'late' => $late,
                'absent' => $absent,
                'missing_clock_out' => $missingClockOut,
                'attended_days' => $attendedDays,
                'working_days_in_period' => $workingDaysCount,
                'attendance_rate' => $attendanceRate,
            ],
            'today' => $todayData,
            'attendance_actions' => $this->attendanceActions($viewer),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function teamSummary(array $filters = []): array
    {
        $month = (int) ($filters['month'] ?? now()->month);
        $year = (int) ($filters['year'] ?? now()->year);
        $periodStart = Carbon::create($year, $month, 1)->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();
        $effectiveTo = $periodEnd->greaterThan(today()) ? today() : $periodEnd;

        $counts = Attendance::query()
            ->whereDate('attendance_date', '>=', $periodStart->toDateString())
            ->whereDate('attendance_date', '<=', $effectiveTo->toDateString())
            ->selectRaw('COUNT(*) as total_records')
            ->selectRaw("SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present")
            ->selectRaw("SUM(CASE WHEN status = 'late' OR is_late = true THEN 1 ELSE 0 END) as late")
            ->selectRaw("SUM(CASE WHEN status = 'missing_clock_out' THEN 1 ELSE 0 END) as missing_clock_out")
            ->first();

        $absentRecords = Attendance::query()
            ->whereDate('attendance_date', '>=', $periodStart->toDateString())
            ->whereDate('attendance_date', '<=', $effectiveTo->toDateString())
            ->where('status', 'absent')
            ->get(['employee_id', 'attendance_date', 'status', 'absent_periods']);
        $recordedAbsences = $this->countRecordedAbsenceDays($absentRecords);
        $unrecordedAbsences = $this->countUnrecordedAbsences($periodStart, $effectiveTo);
        $unrecordedAbsenceRecords = $this->countUnrecordedAbsenceRecords($periodStart, $effectiveTo);

        return [
            'period' => [
                'month' => $month,
                'year' => $year,
                'from' => $periodStart->toDateString(),
                'to' => $periodEnd->toDateString(),
            ],
            'summary' => [
                'total_records' => (int) ($counts?->total_records ?? 0) + $unrecordedAbsenceRecords,
                'present' => (int) ($counts?->present ?? 0),
                'late' => (int) ($counts?->late ?? 0),
                'absent' => $recordedAbsences + $unrecordedAbsences,
                'missing_clock_out' => (int) ($counts?->missing_clock_out ?? 0),
            ],
        ];
    }

    public function countUnrecordedAbsences(
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $employeeId = null,
        bool $includeCurrentDayAfterStart = false,
    ): float {
        $settings = $this->companySettingService->current();
        $count = 0;
        $date = Carbon::parse($from->toDateString())->startOfDay();

        while ($date->lte($to)) {
            $todayThreshold = $includeCurrentDayAfterStart ? $settings->working_start_time : $settings->working_end_time;
            $isCompleted = $date->lt(today())
                || ($date->isToday() && now()->greaterThanOrEqualTo(Carbon::parse($date->toDateString().' '.$todayThreshold)));

            if ($isCompleted && $this->isWorkingDay($date, $settings) && ! $this->isPublicHoliday($date)) {
                $day = $date->toDateString();
                $eligibleEmployees = $this->unrecordedAbsenceEmployees($day, $employeeId);
                $halfDayAbsenceCount = (clone $eligibleEmployees)
                    ->whereHas('leaveRequests', fn (Builder $query) => $query
                        ->where('status', 'approved')
                        ->where('duration_type', 'half_day')
                        ->whereIn('half_day_period', ['morning', 'afternoon'])
                        ->whereDate('start_date', '<=', $day)
                        ->whereDate('end_date', '>=', $day))
                    ->count();

                $count += $eligibleEmployees->count() - ($halfDayAbsenceCount * 0.5);
            }

            $date->addDay();
        }

        return $count;
    }

    public function countUnrecordedAbsenceRecords(
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $employeeId = null,
        bool $includeCurrentDayAfterStart = false,
    ): int {
        $settings = $this->companySettingService->current();
        $count = 0;
        $date = Carbon::parse($from->toDateString())->startOfDay();

        while ($date->lte($to)) {
            $day = $date->toDateString();
            $todayThreshold = $includeCurrentDayAfterStart ? $settings->working_start_time : $settings->working_end_time;
            $isCompleted = $date->lt(today())
                || ($date->isToday() && now()->greaterThanOrEqualTo(Carbon::parse($day.' '.$todayThreshold)));

            if ($isCompleted && $this->isWorkingDay($date, $settings) && ! $this->isPublicHoliday($date)) {
                $count += $this->unrecordedAbsenceEmployees($day, $employeeId)->count();
            }

            $date->addDay();
        }

        return $count;
    }

    /** @return Builder<Employee> */
    protected function unrecordedAbsenceEmployees(string $day, ?int $employeeId = null): Builder
    {
        return Employee::query()
            ->when($employeeId !== null, fn (Builder $query) => $query->whereKey($employeeId))
            ->whereDate('join_date', '<=', $day)
            ->where(function (Builder $query) use ($day): void {
                $query->whereNull('last_working_date')
                    ->orWhereDate('last_working_date', '>=', $day);
            })
            ->whereDoesntHave('attendances', fn (Builder $query) => $query->whereDate('attendance_date', $day))
            ->whereDoesntHave('leaveRequests', function (Builder $query) use ($day): void {
                $query->where('status', 'approved')
                    ->whereDate('start_date', '<=', $day)
                    ->whereDate('end_date', '>=', $day)
                    ->where(function (Builder $leaveQuery): void {
                        $leaveQuery
                            ->where('duration_type', '!=', 'half_day')
                            ->orWhereNull('half_day_period');
                    });
            });
    }

    /**
     * @param  Collection<int, Attendance>  $attendanceRecords
     * @return array<int, float>
     */
    public function recordedAbsenceDaysByEmployee(Collection $attendanceRecords): array
    {
        $absences = $attendanceRecords->where('status', 'absent');
        if ($absences->isEmpty()) {
            return [];
        }

        $employeeIds = $absences->pluck('employee_id')->unique()->values();
        $dates = $absences->map(fn (Attendance $attendance) => $attendance->attendance_date->toDateString());
        $firstDate = $dates->min();
        $lastDate = $dates->max();

        $halfDayLeaves = LeaveRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')
            ->where('duration_type', 'half_day')
            ->whereIn('half_day_period', ['morning', 'afternoon'])
            ->whereDate('start_date', '<=', $lastDate)
            ->whereDate('end_date', '>=', $firstDate)
            ->get(['employee_id', 'start_date', 'end_date']);

        $counts = [];
        foreach ($absences as $absence) {
            $date = $absence->attendance_date->toDateString();
            if (is_array($absence->absent_periods)) {
                $absenceDays = count($absence->absent_periods) * 0.5;
            } else {
                $isHalfDayAbsence = $halfDayLeaves->contains(fn (LeaveRequest $leave): bool => (int) $leave->employee_id === (int) $absence->employee_id
                && $leave->start_date->toDateString() <= $date
                && $leave->end_date->toDateString() >= $date
                );
                $absenceDays = $isHalfDayAbsence ? 0.5 : 1.0;
            }

            $employeeId = (int) $absence->employee_id;
            $counts[$employeeId] = ($counts[$employeeId] ?? 0.0) + $absenceDays;
        }

        return $counts;
    }

    /**
     * @param  Collection<int, Attendance>  $attendanceRecords
     */
    public function countRecordedAbsenceDays(Collection $attendanceRecords): float
    {
        return array_sum($this->recordedAbsenceDaysByEmployee($attendanceRecords));
    }

    /** @return array<int, string> */
    public function approvedLeavePeriods(int $employeeId, string $date): array
    {
        return $this->approvedLeavePeriodsFromCollection(LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->get());
    }

    /** @param Collection<int, LeaveRequest> $leaves
     * @return array<int, string>
     */
    protected function approvedLeavePeriodsFromCollection(Collection $leaves): array
    {
        $periods = [];
        foreach ($leaves as $leave) {
            if ($leave->duration_type === 'half_day' && in_array($leave->half_day_period, ['morning', 'afternoon'], true)) {
                $periods[] = $leave->half_day_period;
            } else {
                $periods = ['morning', 'afternoon'];
                break;
            }
        }

        return array_values(array_unique($periods));
    }

    /** @param array<int, string>|null $leavePeriods
     * @return array<int, string>
     */
    public function absentPeriodsForAttendance(
        ?Attendance $attendance,
        CarbonInterface $date,
        CompanySetting $settings,
        ?array $leavePeriods = null,
    ): array {
        $leavePeriods ??= $attendance
            ? $this->approvedLeavePeriods((int) $attendance->employee_id, $date->toDateString())
            : [];
        $expectedPeriods = array_values(array_diff(['morning', 'afternoon'], $leavePeriods));
        $attendedPeriods = $attendance ? $this->attendedPeriodsForAttendance($attendance, $settings) : [];

        return array_values(array_diff($expectedPeriods, $attendedPeriods));
    }

    /** @return array<int, string> */
    public function attendedPeriodsForAttendance(Attendance $attendance, CompanySetting $settings): array
    {
        return array_values(array_filter([
            $attendance->clock_in_time ? 'morning' : null,
            $attendance->clock_out_time ? 'afternoon' : null,
        ]));
    }

    public function recordedAttendanceDays(Attendance $attendance, ?CompanySetting $settings = null): float
    {
        if ($attendance->corrected_at && in_array($attendance->status, ['present', 'late'], true)) {
            return 1.0;
        }

        return count($this->attendedPeriodsForAttendance($attendance, $settings ?? $this->companySettingService->current())) * 0.5;
    }

    /** @return array{0: string, 1: array<int, string>} */
    protected function statusAfterClockOut(Attendance $attendance, CarbonInterface $clockOut, CompanySetting $settings): array
    {
        $record = Attendance::make([
            'employee_id' => $attendance->employee_id,
            'attendance_date' => $attendance->attendance_date,
            'clock_in_time' => $attendance->clock_in_time,
            'clock_out_time' => $clockOut,
        ]);
        $absentPeriods = $this->absentPeriodsForAttendance(
            $record,
            $attendance->attendance_date,
            $settings,
            $this->approvedLeavePeriods($attendance->employee_id, $attendance->attendance_date->toDateString()),
        );

        if ($absentPeriods !== []) {
            return ['absent', $absentPeriods];
        }

        return [
            $this->isLateForTime($attendance->clock_in_time, 'present', $settings) ? 'late' : 'present',
            [],
        ];
    }

    public function generateQrToken(User $actor): AttendanceQrToken
    {
        $existing = AttendanceQrToken::query()->latest('id')->first();

        if ($existing) {
            return $existing->load('generatedBy');
        }

        return AttendanceQrToken::query()->create([
            'token' => Str::random(64),
            'generated_by' => $actor->id,
        ])->load('generatedBy');
    }

    public function currentQrToken(): ?AttendanceQrToken
    {
        return AttendanceQrToken::query()
            ->latest('id')
            ->first()
            ?->load('generatedBy');
    }

    public function downloadQrImage(AttendanceQrToken $qrToken): Response
    {
        $svg = QrCode::format('svg')
            ->size(400)
            ->margin(2)
            ->generate($qrToken->scan_url);

        return response((string) $svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => 'attachment; filename="attendance-qr.svg"',
        ]);
    }

    public function deleteQrToken(
        AttendanceQrToken $qrToken,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): void {
        $tokenValue = $qrToken->token;

        $qrToken->delete();

        $this->auditLogService->log(
            action: 'qr_token_deleted',
            module: 'attendance',
            user: $actor,
            subject: null,
            oldValues: ['token' => $tokenValue],
            newValues: [],
            ipAddress: $ipAddress,
            userAgent: $userAgent,
        );
    }

    /**
     * @return array{action: string, attendance: Attendance}
     */
    public function scanQr(
        User $user,
        string $token,
        float $latitude,
        float $longitude,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        return DB::transaction(function () use ($user, $token, $latitude, $longitude, $ipAddress, $userAgent): array {
            $qrToken = AttendanceQrToken::query()
                ->where('token', $token)
                ->first();

            if (! $qrToken) {
                throw ApiException::unprocessable('Invalid QR code.');
            }

            $employee = $this->employeeForUserOrFail($user);
            Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $today = now()->toDateString();

            if ($this->isPublicHoliday(now())) {
                throw ApiException::unprocessable('Today is a public holiday and attendance is not required.');
            }

            $settings = $this->companySettingService->current();

            if (! $this->isWorkingDay(now(), $settings)) {
                throw ApiException::unprocessable('Today is not a working day.');
            }

            $this->assertOfficeGpsConfigured($settings);
            $this->assertWithinAllowedLocation($latitude, $longitude, $settings, 'QR scan');

            $attendance = Attendance::query()
                ->whereBelongsTo($employee)
                ->whereDate('attendance_date', $today)
                ->lockForUpdate()
                ->first();

            $at = now();
            $actionName = $at->gte($this->halfDayAfternoonStart($at)) ? 'clock_out' : 'clock_in';
            if ($attendance?->clock_in_time && $attendance?->clock_out_time) {
                throw ApiException::unprocessable('You have already completed your attendance for today.');
            }
            $this->assertAttendanceWindow($actionName, $at, $settings);
            $this->assertLeaveAllowsAttendance($employee, $today, $actionName, 'You are on approved leave today and cannot use QR attendance.');
            if (! $user->hasPermissionTo('attendance.'.$actionName)) {
                throw ApiException::forbidden();
            }

            if ($actionName === 'clock_in') {
                $attendance = $this->recordClockIn($employee, $today, [
                    'clock_in_time' => $at,
                    'clock_in_latitude' => $latitude,
                    'clock_in_longitude' => $longitude,
                    'status' => $this->isLateForEmployee($at, $employee, $settings) ? 'late' : 'present',
                    'is_late' => $this->isLateForEmployee($at, $employee, $settings),
                    'qr_clock_in' => true,
                ], 'You have already clocked in today.');
            } else {
                $attendance = $this->recordClockOut($employee, $today, [
                    'clock_out_time' => $at,
                    'clock_out_latitude' => $latitude,
                    'clock_out_longitude' => $longitude,
                    'qr_clock_out' => true,
                ], 'You have already clocked out today.');
            }
            $action = 'qr_'.$actionName;

            $this->auditLogService->log(
                action: $action,
                module: 'attendance',
                user: $user,
                subject: $attendance,
                oldValues: [],
                newValues: $this->auditAttributes($attendance),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return ['action' => $action, 'attendance' => $attendance];
        });
    }

    /**
     * Reuse an automatic absence record so a scheduled check cannot block a later clock-in.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function recordClockIn(Employee $employee, string $date, array $attributes, string $duplicateMessage): Attendance
    {
        return DB::transaction(function () use ($employee, $date, $attributes, $duplicateMessage): Attendance {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $attendance = Attendance::query()
                ->whereBelongsTo($employee)
                ->whereDate('attendance_date', $date)
                ->lockForUpdate()
                ->first();

            $this->assertLeaveAllowsAttendance($employee, $date, 'clock_in', 'This employee is on approved leave and cannot clock in.');

            if ($attendance?->clock_in_time || ($attendance?->corrected_at && ! $attendance?->clock_out_time)) {
                throw ApiException::unprocessable($duplicateMessage);
            }

            $attendedPeriods = $this->attendedPeriodsForAttendance(Attendance::make([
                'attendance_date' => $date,
                'clock_in_time' => $attributes['clock_in_time'],
                'clock_out_time' => $attendance?->clock_out_time,
            ]), $this->companySettingService->current());
            $attributes['absent_periods'] = array_values(array_diff($attendance?->absent_periods ?? [], $attendedPeriods));
            if ($attributes['absent_periods'] !== []) {
                $attributes['status'] = 'absent';
            }

            $attendance ??= new Attendance(['employee_id' => $employee->id, 'attendance_date' => $date]);
            $attendance->fill($attributes)->save();

            return $attendance->fresh(['employee', 'correctedBy', 'proxiedClockInBy', 'proxiedClockOutBy']);
        });
    }

    protected function countWorkingDays(CarbonInterface $from, CarbonInterface $to): int
    {
        $settings = $this->companySettingService->current();
        $workingDays = collect($settings->working_days ?? [])
            ->map(fn (mixed $day) => strtolower((string) $day))
            ->values()
            ->all();

        $holidays = array_flip(
            PublicHoliday::query()
                ->where('status', Status::Active->value)
                ->whereDate('holiday_date', '>=', $from->toDateString())
                ->whereDate('holiday_date', '<=', $to->toDateString())
                ->pluck('holiday_date')
                ->map(fn (mixed $date) => $date instanceof CarbonInterface ? $date->toDateString() : (string) $date)
                ->all()
        );

        $count = 0;
        $cursor = $from->copy()->startOfDay();
        $rangeEnd = $to->copy()->startOfDay();

        while ($cursor->lte($rangeEnd)) {
            if (
                in_array(strtolower($cursor->format('l')), $workingDays, true)
                && ! array_key_exists($cursor->toDateString(), $holidays)
            ) {
                $count++;
            }
            $cursor->addDay();
        }

        return $count;
    }

    protected function isOnApprovedLeave(Employee $employee, string $date): bool
    {
        return $this->approvedLeaveForDate($employee, $date) !== null;
    }

    protected function approvedLeaveForDate(Employee $employee, string $date): ?LeaveRequest
    {
        return LeaveRequest::query()
            ->whereBelongsTo($employee)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();
    }

    protected function assertLeaveAllowsAttendance(
        Employee $employee,
        string $date,
        string $action,
        string $fullDayError,
    ): void {
        $periods = $this->approvedLeavePeriods($employee->id, $date);
        if (count($periods) === 2) {
            throw ApiException::unprocessable($fullDayError);
        }
        $period = $action === 'clock_in' ? 'morning' : 'afternoon';
        if (in_array($period, $periods, true)) {
            throw ApiException::unprocessable('You have approved '.ucfirst($period).' leave and cannot '.str_replace('_', ' ', $action).'.');
        }
    }

    protected function assertAttendanceWindow(string $action, CarbonInterface $at, CompanySetting $settings): void
    {
        $message = $this->attendanceWindowError($action, $at, $settings);
        if ($message !== null) {
            throw ApiException::unprocessable($message);
        }
    }

    protected function attendanceWindowError(string $action, CarbonInterface $at, CompanySetting $settings): ?string
    {
        $start = $at->copy()->startOfDay();
        $end = $this->halfDayMorningEnd($at);
        $afternoon = $this->halfDayAfternoonStart($at);
        if ($action === 'clock_in' && ($at->lt($start) || $at->gt($end))) {
            return 'Clock In is available from '.$start->format('g:i A').' to '.$end->format('g:i A').'.';
        }
        if ($action === 'clock_out' && $at->lt($afternoon)) {
            return 'Clock Out is available from '.$afternoon->format('g:i A').'.';
        }

        return null;
    }

    /** @return array{available_action: ?string, message: string, complete: bool, refresh_after_seconds: int} */
    public function attendanceActions(User $user): array
    {
        $employee = $this->employeeForUserOrFail($user);
        $settings = $this->companySettingService->current();
        $at = now();
        $date = $at->toDateString();
        $record = Attendance::query()->whereBelongsTo($employee)->whereDate('attendance_date', $date)->first();
        $leavePeriods = $this->approvedLeavePeriods($employee->id, $date);
        $morningDone = (bool) $record?->clock_in_time || in_array('morning', $leavePeriods, true);
        $afternoonDone = (bool) $record?->clock_out_time || in_array('afternoon', $leavePeriods, true);
        $complete = $morningDone && $afternoonDone;
        $action = $at->gte($this->halfDayAfternoonStart($at)) ? 'clock_out' : 'clock_in';
        $message = $this->attendanceWindowError($action, $at, $settings);
        if ($this->isPublicHoliday($at)) {
            $message = 'Today is a public holiday and attendance is not required.';
        } elseif (! $this->isWorkingDay($at, $settings)) {
            $message = 'Today is not a working day.';
        } elseif ($complete) {
            $message = count($leavePeriods) === 2 ? 'You are on approved leave today.' : 'Attendance complete for today.';
        } elseif (in_array($action === 'clock_in' ? 'morning' : 'afternoon', $leavePeriods, true)) {
            $message = 'You have approved '.($action === 'clock_in' ? 'morning' : 'afternoon').' leave.';
        } elseif ($action === 'clock_in' ? (bool) $record?->clock_in_time : (bool) $record?->clock_out_time) {
            $message = $action === 'clock_in' ? 'Morning attendance recorded. Clock Out is available from '.$this->halfDayAfternoonStart($at)->format('g:i A').'.' : 'Afternoon attendance recorded.';
        } elseif (! $user->hasPermissionTo('attendance.'.$action)) {
            $message = 'You do not have permission to record attendance.';
        }
        $boundaries = [
            $this->halfDayMorningEnd($at)->addSecond(),
            $this->halfDayAfternoonStart($at),
            $at->copy()->addDay()->startOfDay(),
        ];
        $refresh = 60;
        foreach ($boundaries as $boundary) {
            if ($boundary->gt($at)) {
                $refresh = min($refresh, max(1, (int) ceil($at->diffInSeconds($boundary))));
            }
        }

        return [
            'available_action' => $message === null ? $action : null,
            'message' => $message ?? ($action === 'clock_in' ? 'Record your morning attendance.' : 'Record your afternoon attendance.'),
            'complete' => $complete,
            'refresh_after_seconds' => $refresh,
        ];
    }

    /** @param array<string, mixed> $attributes */
    protected function recordClockOut(Employee $employee, string $date, array $attributes, string $duplicateMessage): Attendance
    {
        return DB::transaction(function () use ($employee, $date, $attributes, $duplicateMessage): Attendance {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $this->assertLeaveAllowsAttendance($employee, $date, 'clock_out', 'This employee is on approved leave and cannot clock out.');
            $attendance = Attendance::query()->whereBelongsTo($employee)->whereDate('attendance_date', $date)->lockForUpdate()->first();
            if ($attendance?->clock_out_time) {
                throw ApiException::unprocessable($duplicateMessage);
            }
            $attendance ??= new Attendance(['employee_id' => $employee->id, 'attendance_date' => $date]);
            [$status, $absentPeriods] = $this->statusAfterClockOut($attendance, Carbon::parse($attributes['clock_out_time']), $this->companySettingService->current());
            $attendance->fill($attributes + [
                'status' => $status,
                'absent_periods' => $absentPeriods,
                'is_late' => $attendance->is_late || $status === 'late',
            ])->save();

            return $attendance->fresh(['employee', 'correctedBy', 'proxiedClockInBy', 'proxiedClockOutBy']);
        });
    }

    protected function halfDayMorningEnd(CarbonInterface $date): Carbon
    {
        return Carbon::parse($date->toDateString().' '.config('hr.leave.half_day_schedule.morning_end_time', '12:00:00'));
    }

    protected function halfDayAfternoonStart(CarbonInterface $date): Carbon
    {
        return Carbon::parse($date->toDateString().' '.config('hr.leave.half_day_schedule.afternoon_start_time', '13:00:00'));
    }

    protected function isLateForEmployee(CarbonInterface $clockInTime, Employee $employee, CompanySetting $settings): bool
    {
        $leave = $this->approvedLeaveForDate($employee, $clockInTime->toDateString());

        if ($leave?->duration_type === 'half_day' && $leave->half_day_period === 'morning') {
            return $clockInTime->greaterThan($this->halfDayAfternoonStart($clockInTime));
        }

        return $this->isLateForTime($clockInTime, 'present', $settings);
    }

    protected function employeeForUserOrFail(User $user): Employee
    {
        $employee = $user->loadMissing('employee')->employee;

        if (! $employee) {
            throw ApiException::forbidden('No employee profile is linked to this user account.');
        }

        return $employee;
    }

    protected function assertOfficeGpsConfigured(CompanySetting $settings): void
    {
        if ($settings->office_latitude === null || $settings->office_longitude === null) {
            throw ApiException::unprocessable('Office GPS settings are not configured.');
        }
    }

    protected function assertWithinAllowedLocation(
        float $latitude,
        float $longitude,
        CompanySetting $settings,
        string $action,
    ): void {
        $distance = $this->distanceInMeters(
            officeLatitude: (float) $settings->office_latitude,
            officeLongitude: (float) $settings->office_longitude,
            userLatitude: $latitude,
            userLongitude: $longitude,
        );

        if ($distance > (int) $settings->allowed_radius_meters) {
            throw ApiException::unprocessable("You are outside the allowed {$action} location.");
        }
    }

    protected function distanceInMeters(
        float $officeLatitude,
        float $officeLongitude,
        float $userLatitude,
        float $userLongitude,
    ): float {
        $earthRadius = 6371000;
        $latitudeDelta = deg2rad($userLatitude - $officeLatitude);
        $longitudeDelta = deg2rad($userLongitude - $officeLongitude);

        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($officeLatitude)) * cos(deg2rad($userLatitude)) * sin($longitudeDelta / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    protected function isLateForTime(
        CarbonInterface|string|null $clockInTime,
        string $status,
        CompanySetting $settings,
    ): bool {
        if (! $clockInTime || $status === 'absent') {
            return false;
        }

        $clockIn = $clockInTime instanceof CarbonInterface ? $clockInTime : Carbon::parse($clockInTime);
        $workingStartTime = Carbon::parse($clockIn->toDateString().' '.$settings->working_start_time);

        return $clockIn->greaterThan($workingStartTime);
    }

    protected function isWorkingDay(CarbonInterface $date, CompanySetting $settings): bool
    {
        return in_array(strtolower($date->format('l')), $settings->working_days ?? [], true);
    }

    protected function isPublicHoliday(CarbonInterface $date): bool
    {
        return PublicHoliday::query()
            ->where('status', Status::Active->value)
            ->whereDate('holiday_date', $date->toDateString())
            ->exists();
    }

    protected function missingClockOutGraceHours(): int
    {
        return max(0, (int) config('hr.attendance.missing_clock_out_grace_hours', 2));
    }

    protected function missingClockOutDeadline(
        CarbonInterface|string $attendanceDate,
        CompanySetting $settings,
        ?int $graceHours = null,
    ): CarbonInterface {
        $date = $attendanceDate instanceof CarbonInterface
            ? $attendanceDate->toDateString()
            : Carbon::parse($attendanceDate)->toDateString();

        return Carbon::parse($date.' '.$settings->working_end_time)
            ->addHours($graceHours ?? $this->missingClockOutGraceHours());
    }

    protected function hasPassedMissingClockOutDeadline(
        Attendance $attendance,
        CompanySetting $settings,
        ?int $graceHours = null,
    ): bool {
        if (! $attendance->attendance_date || ! $attendance->clock_in_time || $attendance->clock_out_time) {
            return false;
        }

        return now()->greaterThanOrEqualTo(
            $this->missingClockOutDeadline($attendance->attendance_date, $settings, $graceHours)
        );
    }

    protected function reconcileMissingClockOutForEmployee(
        int $employeeId,
        CarbonInterface $from,
        CarbonInterface $to,
    ): void {
        $settings = $this->companySettingService->current();
        $graceHours = $this->missingClockOutGraceHours();

        Attendance::query()
            ->where('employee_id', $employeeId)
            ->whereDate('attendance_date', '>=', $from->toDateString())
            ->whereDate('attendance_date', '<=', $to->toDateString())
            ->whereNotNull('clock_in_time')
            ->whereNull('clock_out_time')
            ->whereIn('status', ['present', 'late'])
            ->orderBy('id')
            ->get()
            ->each(function (Attendance $attendance) use ($settings, $graceHours): void {
                if (! in_array('afternoon', $this->approvedLeavePeriods((int) $attendance->employee_id, $attendance->attendance_date->toDateString()), true) && $this->hasPassedMissingClockOutDeadline($attendance, $settings, $graceHours)) {
                    $attendance->update([
                        'status' => 'missing_clock_out',
                        'is_late' => $attendance->is_late || $attendance->status === 'late',
                    ]);
                }
            });
    }

    /**
     * @return array<string, mixed>
     */
    protected function auditAttributes(Attendance $attendance): array
    {
        return [
            'employee_id' => $attendance->employee_id,
            'attendance_date' => $attendance->attendance_date?->toDateString(),
            'clock_in_time' => $attendance->clock_in_time?->toISOString(),
            'clock_out_time' => $attendance->clock_out_time?->toISOString(),
            'status' => $attendance->status,
            'absent_periods' => $attendance->absent_periods,
            'is_late' => $attendance->is_late,
            'correction_reason' => $attendance->correction_reason,
            'corrected_by' => $attendance->corrected_by,
            'corrected_at' => $attendance->corrected_at?->toISOString(),
            'proxied_clock_in_by' => $attendance->proxied_clock_in_by,
            'proxied_clock_out_by' => $attendance->proxied_clock_out_by,
        ];
    }
}
