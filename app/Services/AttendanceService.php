<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Attendance;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\PublicHoliday;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
        $perPage = (int) ($filters['per_page'] ?? 15);

        $query = Attendance::query()
            ->with(['employee', 'correctedBy', 'proxiedClockInBy', 'proxiedClockOutBy'])
            ->when($filters['attendance_date'] ?? null, fn (Builder $query, string $attendanceDate) => $query->whereDate('attendance_date', $attendanceDate))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $dateFrom) => $query->whereDate('attendance_date', '>=', $dateFrom))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $dateTo) => $query->whereDate('attendance_date', '<=', $dateTo))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderByDesc('attendance_date')
            ->orderByDesc('id');

        if ($viewer->hasPermissionTo('attendance.view_any')) {
            $query->when($filters['employee_id'] ?? null, fn (Builder $query, int $employeeId) => $query->where('employee_id', $employeeId));
        } elseif ($viewer->hasPermissionTo('attendance.view_own')) {
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

        if (Attendance::query()->whereBelongsTo($employee)->whereDate('attendance_date', $attendanceDate)->exists()) {
            throw ApiException::unprocessable('You have already clocked in today.');
        }

        return Attendance::query()->create([
            'employee_id' => $employee->id,
            'attendance_date' => $attendanceDate,
            'clock_in_time' => $clockInAt,
            'clock_in_latitude' => $latitude,
            'clock_in_longitude' => $longitude,
            'status' => $this->isLateForTime($clockInAt, 'present', $settings) ? 'late' : 'present',
            'is_late' => $this->isLateForTime($clockInAt, 'present', $settings),
        ])->load(['employee', 'correctedBy', 'proxiedClockInBy', 'proxiedClockOutBy']);
    }

    public function clockOut(User $user, float $latitude, float $longitude): Attendance
    {
        $employee = $this->employeeForUserOrFail($user);
        $settings = $this->companySettingService->current();

        $this->assertOfficeGpsConfigured($settings);
        $this->assertWithinAllowedLocation($latitude, $longitude, $settings, 'clock-out');

        $attendanceDate = now()->toDateString();
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
            'clock_out_time' => now(),
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

            $attributes['is_late'] = $this->isLateForTime($finalClockInTime, $finalStatus, $settings);

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
                        ->whereDate('end_date', '>=', $date->toDateString());
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

    public function proxyClockIn(
        User $actor,
        int $employeeId,
        string $attendanceDate,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Attendance {
        $employee = Employee::query()->findOrFail($employeeId);
        $settings = $this->companySettingService->current();

        $date        = Carbon::parse($attendanceDate)->startOfDay();
        $clockInTime = Carbon::parse($date->toDateString().' '.$settings->working_start_time);

        if (Attendance::query()->whereBelongsTo($employee)->whereDate('attendance_date', $date->toDateString())->exists()) {
            throw ApiException::unprocessable('An attendance record already exists for this employee on the selected date.');
        }

        $attendance = Attendance::query()->create([
            'employee_id'        => $employee->id,
            'attendance_date'    => $date->toDateString(),
            'clock_in_time'      => $clockInTime,
            'status'             => 'present',
            'is_late'            => false,
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

        $clockOutTime = Carbon::parse($date->toDateString().' '.$settings->working_end_time);
        $oldValues    = $this->auditAttributes($attendance);

        $attendance->update([
            'clock_out_time'       => $clockOutTime,
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
        $year  = (int) ($filters['year'] ?? now()->year);

        $periodStart = Carbon::create($year, $month, 1)->startOfMonth();
        $periodEnd   = $periodStart->copy()->endOfMonth();
        $effectiveTo = $periodEnd->greaterThan(today()) ? today() : $periodEnd;

        $records = Attendance::query()
            ->whereBelongsTo($employee)
            ->whereDate('attendance_date', '>=', $periodStart->toDateString())
            ->whereDate('attendance_date', '<=', $effectiveTo->toDateString())
            ->get(['attendance_date', 'status', 'is_late', 'clock_in_time', 'clock_out_time']);

        $present         = $records->where('status', 'present')->count();
        $late            = $records->where('status', 'late')->count();
        $missingClockOut = $records->where('status', 'missing_clock_out')->count();
        $attendedDays    = $present + $late + $missingClockOut;

        $workingDaysCount = $this->countWorkingDays($periodStart, $effectiveTo);

        // Absent is derived: working days that elapsed with no clock-in, regardless of
        // whether markAbsent has been run. This ensures the count is always correct.
        $absent = max(0, $workingDaysCount - $attendedDays);

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
                'status'         => $todayRecord->status,
                'clock_in_time'  => $todayRecord->clock_in_time?->toISOString(),
                'clock_out_time' => $todayRecord->clock_out_time?->toISOString(),
                'is_late'        => $todayRecord->is_late,
            ] : null;
        }

        return [
            'employee' => [
                'id'          => $employee->id,
                'employee_id' => $employee->employee_id,
                'full_name'   => $employee->full_name,
            ],
            'period' => [
                'month' => $month,
                'year'  => $year,
                'from'  => $periodStart->toDateString(),
                'to'    => $periodEnd->toDateString(),
            ],
            'summary' => [
                'present'                => $present,
                'late'                   => $late,
                'absent'                 => $absent,
                'missing_clock_out'      => $missingClockOut,
                'attended_days'          => $attendedDays,
                'working_days_in_period' => $workingDaysCount,
                'attendance_rate'        => $attendanceRate,
            ],
            'today' => $todayData,
        ];
    }

    protected function countWorkingDays(CarbonInterface $from, CarbonInterface $to): int
    {
        $settings    = $this->companySettingService->current();
        $workingDays = collect($settings->working_days ?? [])
            ->map(fn (mixed $day) => strtolower((string) $day))
            ->values()
            ->all();

        $holidays = array_flip(
            PublicHoliday::query()
                ->where('status', 'active')
                ->whereDate('holiday_date', '>=', $from->toDateString())
                ->whereDate('holiday_date', '<=', $to->toDateString())
                ->pluck('holiday_date')
                ->map(fn (mixed $date) => $date instanceof CarbonInterface ? $date->toDateString() : (string) $date)
                ->all()
        );

        $count    = 0;
        $cursor   = $from->copy()->startOfDay();
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
            ->where('status', 'active')
            ->whereDate('holiday_date', $date->toDateString())
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    protected function auditAttributes(Attendance $attendance): array
    {
        return [
            'employee_id'          => $attendance->employee_id,
            'attendance_date'      => $attendance->attendance_date?->toDateString(),
            'clock_in_time'        => $attendance->clock_in_time?->toISOString(),
            'clock_out_time'       => $attendance->clock_out_time?->toISOString(),
            'status'               => $attendance->status,
            'is_late'              => $attendance->is_late,
            'correction_reason'    => $attendance->correction_reason,
            'corrected_by'         => $attendance->corrected_by,
            'corrected_at'         => $attendance->corrected_at?->toISOString(),
            'proxied_clock_in_by'  => $attendance->proxied_clock_in_by,
            'proxied_clock_out_by' => $attendance->proxied_clock_out_by,
        ];
    }
}
