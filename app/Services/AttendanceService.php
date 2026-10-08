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

        $this->assertLeaveAllowsAttendance($employee, $attendanceDate, 'clock_in', $clockInAt, 'You are on approved leave today and cannot clock in.');

        if ($this->isPublicHoliday($clockInAt)) {
            throw ApiException::unprocessable('Today is a public holiday and attendance is not required.');
        }

        if (! $this->isWorkingDay($clockInAt, $settings)) {
            throw ApiException::unprocessable('Today is not a working day.');
        }

        if (Attendance::query()->whereBelongsTo($employee)->whereDate('attendance_date', $attendanceDate)->exists()) {
            throw ApiException::unprocessable('You have already clocked in today.');
        }

        return Attendance::query()->create([
            'employee_id' => $employee->id,
            'attendance_date' => $attendanceDate,
            'clock_in_time' => $clockInAt,
            'clock_in_latitude' => $latitude,
            'clock_in_longitude' => $longitude,
            'status' => $this->isLateForEmployee($clockInAt, $employee, $settings) ? 'late' : 'present',
            'is_late' => $this->isLateForEmployee($clockInAt, $employee, $settings),
        ])->load(['employee', 'correctedBy', 'proxiedClockInBy', 'proxiedClockOutBy']);
    }

    public function clockOut(User $user, float $latitude, float $longitude): Attendance
    {
        $employee = $this->employeeForUserOrFail($user);
        $settings = $this->companySettingService->current();

        $this->assertOfficeGpsConfigured($settings);
        $this->assertWithinAllowedLocation($latitude, $longitude, $settings, 'clock-out');

        $attendanceDate = now()->toDateString();

        $clockOutAt = now();
        $this->assertLeaveAllowsAttendance($employee, $attendanceDate, 'clock_out', $clockOutAt, 'You are on approved leave today and cannot clock out.');

        if ($this->isPublicHoliday(now())) {
            throw ApiException::unprocessable('Today is a public holiday and attendance is not required.');
        }

        if (! $this->isWorkingDay(now(), $settings)) {
            throw ApiException::unprocessable('Today is not a working day.');
        }

        $attendance = Attendance::query()
            ->whereBelongsTo($employee)
            ->whereDate('attendance_date', $attendanceDate)
            ->first();

        if (! $attendance || ! $attendance->clock_in_time) {
            throw ApiException::unprocessable('You must clock in before clocking out.');
        }

        if ($attendance->clock_out_time) {
            throw ApiException::unprocessable('You have already clocked out today.');
        }

        $attendance->update([
            'clock_out_time' => $clockOutAt,
            'clock_out_latitude' => $latitude,
            'clock_out_longitude' => $longitude,
            'status' => $attendance->status === 'missing_clock_out'
                ? ($this->isLateForTime($attendance->clock_in_time, 'present', $settings) ? 'late' : 'present')
                : $attendance->status,
        ]);

        return $attendance->fresh(['employee', 'correctedBy', 'proxiedClockInBy', 'proxiedClockOutBy']);
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

    /**
     * Removes stale 'absent' attendance records for dates a leave request now covers.
     *
     * Approved-leave days never get an attendance row under normal clock-in flow (it's
     * blocked upfront), so this reconciles the case where markAbsent already ran for a
     * date before a retroactive/backdated leave request was approved for it.
     */
    public function reconcileApprovedLeave(int $employeeId, CarbonInterface $startDate, CarbonInterface $endDate): int
    {
        return Attendance::query()
            ->where('employee_id', $employeeId)
            ->where('status', 'absent')
            ->whereDate('attendance_date', '>=', $startDate->toDateString())
            ->whereDate('attendance_date', '<=', $endDate->toDateString())
            ->delete();
    }

    /**
     * @return array{attendance_date: string, created_count: int}
     */
    public function markAbsent(?string $attendanceDate = null): array
    {
        $date = $attendanceDate ? Carbon::parse($attendanceDate)->startOfDay() : today();
        $settings = $this->companySettingService->current();

        if (! $this->isWorkingDay($date, $settings) || $this->isPublicHoliday($date)) {
            return [
                'attendance_date' => $date->toDateString(),
                'created_count' => 0,
            ];
        }

        $createdCount = DB::transaction(function () use ($date): int {
            $employees = Employee::query()
                ->whereDate('join_date', '<=', $date->toDateString())
                ->where(function (Builder $query) use ($date): void {
                    $query
                        ->whereNull('last_working_date')
                        ->orWhereDate('last_working_date', '>=', $date->toDateString());
                })
                ->whereDoesntHave('attendances', fn (Builder $query) => $query->whereDate('attendance_date', $date->toDateString()))
                ->whereDoesntHave('leaveRequests', function (Builder $query) use ($date): void {
                    $query
                        ->where('status', 'approved')
                        ->whereDate('start_date', '<=', $date->toDateString())
                        ->whereDate('end_date', '>=', $date->toDateString())
                        ->where(function (Builder $leaveQuery): void {
                            $leaveQuery
                                ->where('duration_type', '!=', 'half_day')
                                ->orWhereNull('half_day_period');
                        });
                })
                ->lockForUpdate()
                ->get(['id']);

            foreach ($employees as $employee) {
                Attendance::query()->create([
                    'employee_id' => $employee->id,
                    'attendance_date' => $date->toDateString(),
                    'status' => 'absent',
                    'is_late' => false,
                ]);
            }

            return $employees->count();
        });

        return [
            'attendance_date' => $date->toDateString(),
            'created_count' => $createdCount,
        ];
    }

    /**
     * Flip open clock-ins to missing_clock_out after working_end_time + grace hours.
     *
     * @return array{attendance_date: string|null, updated_count: int}
     */
    public function markMissingClockOut(?string $attendanceDate = null): array
    {
        $settings = $this->companySettingService->current();
        $graceHours = $this->missingClockOutGraceHours();
        $date = $attendanceDate ? Carbon::parse($attendanceDate)->toDateString() : null;

        $updatedCount = DB::transaction(function () use ($settings, $graceHours, $date): int {
            $query = Attendance::query()
                ->whereNotNull('clock_in_time')
                ->whereNull('clock_out_time')
                ->whereIn('status', ['present', 'late'])
                ->when($date, fn (Builder $query) => $query->whereDate('attendance_date', $date))
                ->lockForUpdate()
                ->orderBy('id');

            $updated = 0;

            foreach ($query->get() as $attendance) {
                if (! $this->hasPassedMissingClockOutDeadline($attendance, $settings, $graceHours)) {
                    continue;
                }

                $attendance->update([
                    'status' => 'missing_clock_out',
                    'is_late' => $attendance->is_late || $attendance->status === 'late',
                ]);
                $updated++;
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
        $approvedLeave = $this->approvedLeaveForDate($employee, $date->toDateString());
        $clockInTime = $approvedLeave?->duration_type === 'half_day' && $approvedLeave->half_day_period === 'morning'
            ? $this->halfDayAfternoonStart($date)
            : Carbon::parse($date->toDateString().' '.$settings->working_start_time);
        $this->assertLeaveAllowsAttendance($employee, $date->toDateString(), 'clock_in', $clockInTime, 'This employee is on approved leave on the selected date and cannot be clocked in.');

        if (Attendance::query()->whereBelongsTo($employee)->whereDate('attendance_date', $date->toDateString())->exists()) {
            throw ApiException::unprocessable('An attendance record already exists for this employee on the selected date.');
        }

        $attendance = Attendance::query()->create([
            'employee_id' => $employee->id,
            'attendance_date' => $date->toDateString(),
            'clock_in_time' => $clockInTime,
            'status' => 'present',
            'is_late' => false,
            'proxied_clock_in_by' => $actor->id,
        ])->load(['employee', 'correctedBy', 'proxiedClockInBy', 'proxiedClockOutBy']);

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

        $approvedLeave = $this->approvedLeaveForDate($employee, $date->toDateString());
        $clockOutTime = $approvedLeave?->duration_type === 'half_day' && $approvedLeave->half_day_period === 'afternoon'
            ? $this->halfDayMorningEnd($date)
            : Carbon::parse($date->toDateString().' '.$settings->working_end_time);
        $this->assertLeaveAllowsAttendance($employee, $date->toDateString(), 'clock_out', $clockOutTime, 'This employee is on approved leave on the selected date and cannot be clocked out.');

        $attendance = Attendance::query()
            ->whereBelongsTo($employee)
            ->whereDate('attendance_date', $date->toDateString())
            ->first();

        if (! $attendance) {
            throw ApiException::unprocessable('No clock-in record found for this employee on the selected date. Clock in first.');
        }

        if ($attendance->clock_out_time) {
            throw ApiException::unprocessable('This employee has already clocked out on the selected date.');
        }

        $oldValues = $this->auditAttributes($attendance);

        $attendance->update([
            'clock_out_time' => $clockOutTime,
            'proxied_clock_out_by' => $actor->id,
            // Fix missing_clock_out status now that clock-out is provided
            'status' => $attendance->status === 'missing_clock_out'
                ? ($attendance->is_late ? 'late' : 'present')
                : $attendance->status,
        ]);

        $attendance = $attendance->fresh(['employee', 'correctedBy', 'proxiedClockInBy', 'proxiedClockOutBy']);

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
            ->get(['employee_id', 'attendance_date', 'status', 'is_late', 'clock_in_time', 'clock_out_time']);

        $present = $records->where('status', 'present')->count();
        // A manual late status and a time-based late flag both count as late.
        $late = $records->filter(fn (Attendance $record) => $record->status === 'late' || $record->is_late)->count();
        $absent = ($this->recordedAbsenceDaysByEmployee($records)[$employee->id] ?? 0.0)
            + $this->countUnrecordedAbsences($periodStart, $effectiveTo, $employee->id);
        $missingClockOut = $records->where('status', 'missing_clock_out')->count();
        $attendedDays = $records
            ->filter(fn (Attendance $record) => in_array($record->status, ['present', 'late', 'missing_clock_out'], true))
            ->count();

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
            ->get(['employee_id', 'attendance_date', 'status']);
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
     * @param  \Illuminate\Support\Collection<int, Attendance>  $attendanceRecords
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
            $isHalfDayAbsence = $halfDayLeaves->contains(fn (LeaveRequest $leave): bool =>
                (int) $leave->employee_id === (int) $absence->employee_id
                && $leave->start_date->toDateString() <= $date
                && $leave->end_date->toDateString() >= $date
            );

            $employeeId = (int) $absence->employee_id;
            $counts[$employeeId] = ($counts[$employeeId] ?? 0.0) + ($isHalfDayAbsence ? 0.5 : 1.0);
        }

        return $counts;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Attendance>  $attendanceRecords
     */
    public function countRecordedAbsenceDays(Collection $attendanceRecords): float
    {
        return array_sum($this->recordedAbsenceDaysByEmployee($attendanceRecords));
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

            if (! $attendance) {
                $clockInAt = now();

                $this->assertLeaveAllowsAttendance($employee, $today, 'clock_in', $clockInAt, 'You are on approved leave today and cannot use QR attendance.');

                $isLate = $this->isLateForEmployee($clockInAt, $employee, $settings);

                $attendance = Attendance::query()->create([
                    'employee_id' => $employee->id,
                    'attendance_date' => $today,
                    'clock_in_time' => $clockInAt,
                    'clock_in_latitude' => $latitude,
                    'clock_in_longitude' => $longitude,
                    'status' => $isLate ? 'late' : 'present',
                    'is_late' => $isLate,
                    'qr_clock_in' => true,
                ])->load(['employee', 'correctedBy', 'proxiedClockInBy', 'proxiedClockOutBy']);

                $action = 'qr_clock_in';
            } elseif (! $attendance->clock_out_time) {
                $clockOutAt = now();
                $this->assertLeaveAllowsAttendance($employee, $today, 'clock_out', $clockOutAt, 'You are on approved leave today and cannot use QR attendance.');

                $attendance->update([
                    'clock_out_time' => $clockOutAt,
                    'clock_out_latitude' => $latitude,
                    'clock_out_longitude' => $longitude,
                    'qr_clock_out' => true,
                    'status' => $attendance->status === 'missing_clock_out'
                        ? ($attendance->is_late ? 'late' : 'present')
                        : $attendance->status,
                ]);

                $attendance = $attendance->fresh(['employee', 'correctedBy', 'proxiedClockInBy', 'proxiedClockOutBy']);
                $action = 'qr_clock_out';
            } else {
                throw ApiException::unprocessable('You have already completed your attendance for today.');
            }

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
        CarbonInterface $actionTime,
        string $fullDayError,
    ): void {
        $leave = $this->approvedLeaveForDate($employee, $date);

        if (! $leave) {
            return;
        }

        if ($leave->duration_type !== 'half_day' || ! in_array($leave->half_day_period, ['morning', 'afternoon'], true)) {
            throw ApiException::unprocessable($fullDayError);
        }

        $morningEnd = $this->halfDayMorningEnd(Carbon::parse($date)->startOfDay());
        $afternoonStart = $this->halfDayAfternoonStart(Carbon::parse($date)->startOfDay());
        $message = null;

        if ($leave->half_day_period === 'morning' && $action === 'clock_in' && $actionTime->lt($afternoonStart)) {
            $message = 'You have approved Half Day Morning leave. You can clock in from '.$afternoonStart->format('g:i A').'.';
        } elseif ($leave->half_day_period === 'morning' && $action === 'clock_out' && $actionTime->lt($afternoonStart)) {
            $message = 'You have approved Half Day Morning leave. You can clock out after starting work from '.$afternoonStart->format('g:i A').'.';
        } elseif ($leave->half_day_period === 'afternoon' && $action === 'clock_in' && $actionTime->gt($morningEnd)) {
            $message = 'You have approved Half Day Afternoon leave. You can clock in during morning work hours, before '.$morningEnd->format('g:i A').'.';
        } elseif ($leave->half_day_period === 'afternoon' && $action === 'clock_out' && $actionTime->gt($morningEnd)) {
            $message = 'You have approved Half Day Afternoon leave. Please clock out by '.$morningEnd->format('g:i A').'.';
        }

        if ($message !== null) {
            throw ApiException::unprocessable($message);
        }
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
                if ($this->hasPassedMissingClockOutDeadline($attendance, $settings, $graceHours)) {
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
            'is_late' => $attendance->is_late,
            'correction_reason' => $attendance->correction_reason,
            'corrected_by' => $attendance->corrected_by,
            'corrected_at' => $attendance->corrected_at?->toISOString(),
            'proxied_clock_in_by' => $attendance->proxied_clock_in_by,
            'proxied_clock_out_by' => $attendance->proxied_clock_out_by,
        ];
    }
}
