<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PublicHoliday;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class LeaveService
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

        $query = LeaveRequest::query()
            ->with([
                'employee:id,user_id,employee_id,full_name',
                'hrApprovedBy:id,name',
                'ceoApprovedBy:id,name',
            ])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['leave_type'] ?? null, fn (Builder $query, string $leaveType) => $query->where('leave_type', $leaveType))
            ->orderByDesc('start_date')
            ->orderByDesc('id');

        if ($filters['date_from'] ?? null) {
            $query->whereDate('end_date', '>=', (string) $filters['date_from']);
        }

        if ($filters['date_to'] ?? null) {
            $query->whereDate('start_date', '<=', (string) $filters['date_to']);
        }

        if ($this->canViewAllLeaves($viewer)) {
            $query->when($filters['employee_id'] ?? null, fn (Builder $query, int $employeeId) => $query->where('employee_id', $employeeId));
        } elseif ($this->canViewOwnLeaves($viewer)) {
            $query->whereBelongsTo($this->employeeForUserOrFail($viewer));
        } else {
            throw ApiException::forbidden();
        }

        return $query->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): LeaveRequest
    {
        return DB::transaction(function () use ($data, $actor): LeaveRequest {
            $employee = Employee::query()
                ->whereKey($this->employeeForUserOrFail($actor)->id)
                ->lockForUpdate()
                ->firstOrFail();

            $startDate = Carbon::parse((string) $data['start_date'])->startOfDay();
            $endDate = Carbon::parse((string) $data['end_date'])->startOfDay();
            $durationType = (string) $data['duration_type'];
            $leaveType = (string) $data['leave_type'];

            $totalDays = $this->calculateLeaveDays($startDate, $endDate, $durationType);

            if ($totalDays <= 0) {
                throw ApiException::unprocessable('The selected leave dates do not include any working days.');
            }

            $this->assertLeaveBalanceAvailable($employee, $leaveType, $startDate, $endDate, $durationType);

            $leave = LeaveRequest::query()->create([
                'employee_id' => $employee->id,
                'leave_type' => $leaveType,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'duration_type' => $durationType,
                'total_days' => $totalDays,
                'reason' => $data['reason'],
                'status' => 'pending',
                'hr_approval_status' => $actor->hasRole('hr') ? 'approved' : 'pending',
                'ceo_approval_status' => 'pending',
            ]);

            return $this->loadRelations($leave);
        });
    }

    /**
     * @return array{employee: array<string, mixed>, year: int, balances: array<int, array<string, mixed>>}
     */
    public function balances(Employee $employee, int $year): array
    {
        return [
            'employee' => [
                'id' => $employee->id,
                'employee_id' => $employee->employee_id,
                'full_name' => $employee->full_name,
            ],
            'year' => $year,
            'balances' => $this->balanceRows($employee, $year),
        ];
    }

    public function approve(
        LeaveRequest $leave,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): LeaveRequest {
        return DB::transaction(function () use ($leave, $actor, $ipAddress, $userAgent): LeaveRequest {
            $leave = LeaveRequest::query()->whereKey($leave->id)->lockForUpdate()->firstOrFail();
            $this->assertPendingForDecision($leave);
            $oldValues = $this->auditAttributes($leave);

            if ($actor->hasRole('hr')) {
                if ($leave->hr_approval_status === 'approved') {
                    throw ApiException::unprocessable('HR has already approved this leave request.');
                }

                $leave->forceFill([
                    'hr_approval_status' => 'approved',
                    'hr_approved_by' => $actor->id,
                    'hr_approved_at' => now(),
                ]);
            } elseif ($actor->hasRole('ceo')) {
                if ($leave->ceo_approval_status === 'approved') {
                    throw ApiException::unprocessable('CEO has already approved this leave request.');
                }

                $leave->forceFill([
                    'ceo_approval_status' => 'approved',
                    'ceo_approved_by' => $actor->id,
                    'ceo_approved_at' => now(),
                ]);
            } else {
                throw ApiException::forbidden();
            }

            $leave->status = $leave->hr_approval_status === 'approved' && $leave->ceo_approval_status === 'approved'
                ? 'approved'
                : 'pending';
            $leave->save();
            $leave = $this->loadRelations($leave->fresh());

            $this->auditLogService->log(
                action: 'approve',
                module: 'leaves',
                user: $actor,
                subject: $leave,
                oldValues: $oldValues,
                newValues: $this->auditAttributes($leave),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $leave;
        });
    }

    public function reject(
        LeaveRequest $leave,
        User $actor,
        string $rejectionReason,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): LeaveRequest {
        return DB::transaction(function () use ($leave, $actor, $rejectionReason, $ipAddress, $userAgent): LeaveRequest {
            $leave = LeaveRequest::query()->whereKey($leave->id)->lockForUpdate()->firstOrFail();
            $this->assertPendingForDecision($leave);
            $oldValues = $this->auditAttributes($leave);

            if ($actor->hasRole('hr')) {
                if ($leave->hr_approval_status === 'rejected') {
                    throw ApiException::unprocessable('HR has already rejected this leave request.');
                }

                $leave->forceFill([
                    'hr_approval_status' => 'rejected',
                    'hr_approved_by' => $actor->id,
                    'hr_approved_at' => now(),
                ]);
            } elseif ($actor->hasRole('ceo')) {
                if ($leave->ceo_approval_status === 'rejected') {
                    throw ApiException::unprocessable('CEO has already rejected this leave request.');
                }

                $leave->forceFill([
                    'ceo_approval_status' => 'rejected',
                    'ceo_approved_by' => $actor->id,
                    'ceo_approved_at' => now(),
                ]);
            } else {
                throw ApiException::forbidden();
            }

            $leave->forceFill([
                'status' => 'rejected',
                'rejection_reason' => $rejectionReason,
            ]);
            $leave->save();
            $leave = $this->loadRelations($leave->fresh());

            $this->auditLogService->log(
                action: 'reject',
                module: 'leaves',
                user: $actor,
                subject: $leave,
                oldValues: $oldValues,
                newValues: $this->auditAttributes($leave),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $leave;
        });
    }

    public function cancel(
        LeaveRequest $leave,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): LeaveRequest {
        return DB::transaction(function () use ($leave, $actor, $ipAddress, $userAgent): LeaveRequest {
            $leave = LeaveRequest::query()->whereKey($leave->id)->lockForUpdate()->firstOrFail();

            if ($leave->status !== 'pending') {
                throw ApiException::unprocessable('Only pending leave requests can be cancelled.');
            }

            $oldValues = $this->auditAttributes($leave);

            $leave->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);
            $leave->save();
            $leave = $this->loadRelations($leave->fresh());

            $this->auditLogService->log(
                action: 'cancel',
                module: 'leaves',
                user: $actor,
                subject: $leave,
                oldValues: $oldValues,
                newValues: $this->auditAttributes($leave),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $leave;
        });
    }

    protected function employeeForUserOrFail(User $user): Employee
    {
        $employee = $user->loadMissing('employee')->employee;

        if (! $employee) {
            throw ApiException::forbidden('No employee profile is linked to this user account.');
        }

        return $employee;
    }

    protected function canViewAllLeaves(User $user): bool
    {
        return $user->hasPermissionTo('leaves.view_any');
    }

    protected function canViewOwnLeaves(User $user): bool
    {
        return $user->hasPermissionTo('leaves.view_own');
    }

    protected function assertPendingForDecision(LeaveRequest $leave): void
    {
        if ($leave->status === 'cancelled') {
            throw ApiException::unprocessable('Cancelled leave requests cannot be approved or rejected.');
        }

        if ($leave->status === 'approved') {
            throw ApiException::unprocessable('This leave request has already been fully approved.');
        }

        if ($leave->status === 'rejected') {
            throw ApiException::unprocessable('This leave request has already been rejected.');
        }
    }

    protected function assertLeaveBalanceAvailable(
        Employee $employee,
        string $leaveType,
        CarbonInterface $startDate,
        CarbonInterface $endDate,
        string $durationType,
    ): void {
        if ($leaveType === 'unpaid') {
            return;
        }

        if ($leaveType === 'maternity') {
            if ($employee->gender !== 'female') {
                throw ApiException::unprocessable('Maternity Leave is for female employee only!');
            }

            $totalDays = $this->calculateLeaveDays($startDate, $endDate, $durationType);
            $entitlement = (float) config('hr.leave.entitlements.maternity', 90);

            if ($totalDays > $entitlement) {
                throw ApiException::unprocessable("Requested maternity leave exceeds the {$entitlement}-day entitlement for a single case.");
            }

            return;
        }

        if ($leaveType === 'special_sick') {
            $tenureYears = (int) config('hr.leave.special_sick.tenure_years', 1);
            if (! $employee->join_date || $employee->join_date->diffInYears(now()) < $tenureYears) {
                throw ApiException::unprocessable("Special Sick Leave requires at least {$tenureYears} year(s) of service.");
            }

            $year = $startDate->year;
            $existingCase = LeaveRequest::query()
                ->where('employee_id', $employee->id)
                ->where('leave_type', 'special_sick')
                ->whereIn('status', ['approved', 'pending'])
                ->whereYear('start_date', $year)
                ->exists();

            if ($existingCase) {
                throw ApiException::unprocessable('Only one Special Sick Leave case is allowed per calendar year.');
            }

            $totalDays = $this->calculateLeaveDays($startDate, $endDate, $durationType);
            $entitlement = (float) config('hr.leave.entitlements.special_sick', 180);

            if ($totalDays > $entitlement) {
                throw ApiException::unprocessable("Requested special sick leave exceeds the {$entitlement}-day entitlement per case.");
            }

            return;
        }

        foreach ($this->requestedDaysByYear($startDate, $endDate, $durationType) as $year => $requestedDays) {
            $balance = $this->balanceForLeaveType($employee, $leaveType, (int) $year);

            if ($requestedDays > (float) $balance['remaining']) {
                throw ApiException::unprocessable("Requested {$leaveType} leave exceeds the available {$year} balance.");
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function balanceRows(Employee $employee, int $year): array
    {
        return [
            $this->balanceForLeaveType($employee, 'annual', $year),
            $this->balanceForLeaveType($employee, 'sick', $year),
            $this->balanceForLeaveType($employee, 'special', $year),
            [
                'leave_type' => 'maternity',
                'entitlement' => $this->formatDecimal((float) config('hr.leave.entitlements.maternity', 90)),
                'used' => $this->formatDecimal(0),
                'remaining' => $this->formatDecimal((float) config('hr.leave.entitlements.maternity', 90)),
                'is_unlimited' => false,
                'rule' => 'per_case',
            ],
            [
                'leave_type' => 'special_sick',
                'entitlement' => $this->formatDecimal((float) config('hr.leave.entitlements.special_sick', 180)),
                'used' => $this->formatDecimal($this->approvedLeaveDaysForYear($employee, 'special_sick', $year)),
                'remaining' => $this->formatDecimal(max(0, (float) config('hr.leave.entitlements.special_sick', 180) - $this->approvedLeaveDaysForYear($employee, 'special_sick', $year))),
                'is_unlimited' => false,
                'rule' => 'per_case_per_year',
            ],
            [
                'leave_type' => 'unpaid',
                'entitlement' => null,
                'used' => null,
                'remaining' => null,
                'is_unlimited' => true,
                'rule' => 'unlimited',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function balanceForLeaveType(Employee $employee, string $leaveType, int $year): array
    {
        $used = $this->approvedLeaveDaysForYear($employee, $leaveType, $year);
        $entitlement = (float) config("hr.leave.entitlements.{$leaveType}", 0);
        $remaining = max(0, $entitlement - $used);

        return [
            'leave_type' => $leaveType,
            'entitlement' => $this->formatDecimal($entitlement),
            'used' => $this->formatDecimal($used),
            'remaining' => $this->formatDecimal($remaining),
            'is_unlimited' => false,
            'rule' => 'per_year',
        ];
    }

    protected function approvedLeaveDaysForYear(Employee $employee, string $leaveType, int $year): float
    {
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->startOfDay();

        return LeaveRequest::query()
            ->whereBelongsTo($employee)
            ->where('status', 'approved')
            ->where('leave_type', $leaveType)
            ->whereDate('start_date', '<=', $yearEnd->toDateString())
            ->whereDate('end_date', '>=', $yearStart->toDateString())
            ->get(['start_date', 'end_date', 'duration_type'])
            ->sum(function (LeaveRequest $leave) use ($yearStart, $yearEnd): float {
                $segmentStart = $leave->start_date->greaterThan($yearStart) ? $leave->start_date : $yearStart;
                $segmentEnd = $leave->end_date->lessThan($yearEnd) ? $leave->end_date : $yearEnd;

                return $this->calculateLeaveDays($segmentStart, $segmentEnd, $leave->duration_type);
            });
    }

    /**
     * @return array<int, float>
     */
    protected function requestedDaysByYear(
        CarbonInterface $startDate,
        CarbonInterface $endDate,
        string $durationType,
    ): array {
        $daysByYear = [];

        for ($year = $startDate->year; $year <= $endDate->year; $year++) {
            $segmentStart = $startDate->year === $year ? $startDate->copy() : Carbon::create($year, 1, 1)->startOfDay();
            $segmentEnd = $endDate->year === $year ? $endDate->copy() : Carbon::create($year, 12, 31)->startOfDay();
            $days = $this->calculateLeaveDays($segmentStart, $segmentEnd, $durationType);

            if ($days > 0) {
                $daysByYear[$year] = $days;
            }
        }

        return $daysByYear;
    }

    protected function calculateLeaveDays(
        CarbonInterface $startDate,
        CarbonInterface $endDate,
        string $durationType,
    ): float {
        if ($durationType === 'half_day' && ! $startDate->isSameDay($endDate)) {
            throw ApiException::unprocessable('Half-day leave must start and end on the same date.');
        }

        $settings = $this->companySettingService->current();
        $workingDays = collect($settings->working_days ?? [])
            ->map(fn (mixed $day) => strtolower((string) $day))
            ->values()
            ->all();
        $holidayDates = array_flip(
            PublicHoliday::query()
                ->where('status', 'active')
                ->whereDate('holiday_date', '>=', $startDate->toDateString())
                ->whereDate('holiday_date', '<=', $endDate->toDateString())
                ->pluck('holiday_date')
                ->map(fn (mixed $date) => $date instanceof CarbonInterface ? $date->toDateString() : (string) $date)
                ->filter()
                ->all()
        );

        $days = 0.0;
        $cursor = $startDate->copy()->startOfDay();
        $rangeEnd = $endDate->copy()->startOfDay();

        while ($cursor->lte($rangeEnd)) {
            $dateString = $cursor->toDateString();

            if (
                in_array(strtolower($cursor->format('l')), $workingDays, true)
                && ! array_key_exists($dateString, $holidayDates)
            ) {
                $days++;
            }

            $cursor->addDay();
        }

        if ($days === 0.0) {
            return 0.0;
        }

        return $durationType === 'half_day' ? 0.5 : $days;
    }

    protected function loadRelations(LeaveRequest $leave): LeaveRequest
    {
        return $leave->load([
            'employee:id,user_id,employee_id,full_name',
            'hrApprovedBy:id,name',
            'ceoApprovedBy:id,name',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function auditAttributes(LeaveRequest $leave): array
    {
        return [
            'employee_id' => $leave->employee_id,
            'leave_type' => $leave->leave_type,
            'start_date' => $leave->start_date?->toDateString(),
            'end_date' => $leave->end_date?->toDateString(),
            'duration_type' => $leave->duration_type,
            'total_days' => (string) $leave->total_days,
            'status' => $leave->status,
            'hr_approval_status' => $leave->hr_approval_status,
            'hr_approved_by' => $leave->hr_approved_by,
            'hr_approved_at' => $leave->hr_approved_at?->toISOString(),
            'ceo_approval_status' => $leave->ceo_approval_status,
            'ceo_approved_by' => $leave->ceo_approved_by,
            'ceo_approved_at' => $leave->ceo_approved_at?->toISOString(),
            'rejection_reason' => $leave->rejection_reason,
            'cancelled_at' => $leave->cancelled_at?->toISOString(),
        ];
    }

    protected function formatDecimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
