<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Attendance;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PayrollBatch;
use App\Models\PayrollItem;
use App\Models\PublicHoliday;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PayrollService
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

        $query = PayrollBatch::query()
            ->with([
                'generatedBy:id,name,email',
                'submittedBy:id,name,email',
                'approvedBy:id,name,email',
                'rejectedBy:id,name,email',
            ])
            ->withCount('items')
            ->withSum('items as total_gross_salary', 'gross_salary')
            ->withSum('items as total_unpaid_deduction', 'unpaid_deduction')
            ->withSum('items as total_absence_deduction', 'absence_deduction')
            ->withSum('items as total_tax_amount', 'tax_amount')
            ->withSum('items as total_nssf_deduction', 'nssf_deduction')
            ->withSum('items as total_net_salary', 'net_salary')
            ->when($filters['month'] ?? null, fn (Builder $query, int $month) => $query->where('month', $month))
            ->when($filters['year'] ?? null, fn (Builder $query, int $year) => $query->where('year', $year))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderByDesc('id');

        if ($viewer->hasPermissionTo('payrolls.view_any')) {
            $query->when(
                $filters['employee_id'] ?? null,
                fn (Builder $query, int $employeeId) => $query->whereHas('items', fn (Builder $itemQuery) => $itemQuery->where('employee_id', $employeeId)),
            );
        } elseif ($viewer->hasPermissionTo('payrolls.view_own')) {
            $employee = $viewer->loadMissing('employee')->employee;

            if (! $employee) {
                throw ApiException::forbidden('No employee profile is linked to this user account.');
            }

            $query
                ->where('status', 'approved')
                ->whereHas('items', fn (Builder $itemQuery) => $itemQuery->where('employee_id', $employee->id));
        } else {
            throw ApiException::forbidden();
        }

        return $query->paginate($perPage);
    }

    public function generate(
        int $month,
        int $year,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): PayrollBatch {
        try {
            return DB::transaction(function () use ($month, $year, $actor, $ipAddress, $userAgent): PayrollBatch {
                if (PayrollBatch::query()->where('month', $month)->where('year', $year)->lockForUpdate()->exists()) {
                    throw ApiException::unprocessable('Payroll has already been generated for the selected month and year.');
                }

                $periodStart = Carbon::create($year, $month, 1)->startOfDay();
                $periodEnd = $periodStart->copy()->endOfMonth()->startOfDay();
                $settings = $this->companySettingService->current();

                $employees = Employee::query()
                    ->whereDate('join_date', '<=', $periodEnd->toDateString())
                    ->where(function (Builder $query) use ($periodStart): void {
                        $query
                            ->whereNull('last_working_date')
                            ->orWhereDate('last_working_date', '>=', $periodStart->toDateString());
                    })
                    ->lockForUpdate()
                    ->get([
                        'id',
                        'user_id',
                        'employee_id',
                        'full_name',
                        'join_date',
                        'last_working_date',
                        'base_salary',
                        'employment_status',
                    ]);

                if ($employees->isEmpty()) {
                    throw ApiException::unprocessable('No eligible employees were found for the selected payroll period.');
                }

                $employeeIds = $employees->modelKeys();
                $batch = PayrollBatch::query()->create([
                    'month' => $month,
                    'year' => $year,
                    'status' => 'draft',
                    'generated_by' => $actor->id,
                    'generated_at' => now(),
                ]);

                $holidayDates = $this->holidayDates($periodStart, $periodEnd);
                $attendancesByEmployee = $this->attendanceRowsByEmployee($employeeIds, $periodStart, $periodEnd);
                $approvedLeavesByEmployee = $this->approvedLeavesByEmployee($employeeIds, $periodStart, $periodEnd);

                foreach ($employees as $employee) {
                    $itemPayload = $this->buildGeneratedItemPayload(
                        employee: $employee,
                        settings: $settings,
                        periodStart: $periodStart,
                        periodEnd: $periodEnd,
                        holidayDates: $holidayDates,
                        attendanceRows: $attendancesByEmployee->get($employee->id, collect()),
                        leaveRows: $approvedLeavesByEmployee->get($employee->id, collect()),
                    );

                    PayrollItem::query()->create(array_merge($itemPayload, [
                        'payroll_batch_id' => $batch->id,
                        'employee_id' => $employee->id,
                        'status' => 'draft',
                    ]));
                }

                $batch = $this->loadBatchRelations($batch->fresh());

                $this->auditLogService->log(
                    action: 'generate',
                    module: 'payrolls',
                    user: $actor,
                    subject: $batch,
                    newValues: $this->auditBatchAttributes($batch),
                    ipAddress: $ipAddress,
                    userAgent: $userAgent,
                );

                return $batch;
            }, 3);
        } catch (QueryException $exception) {
            if ($this->isDuplicatePayrollException($exception)) {
                throw ApiException::unprocessable('Payroll has already been generated for the selected month and year.');
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(
        PayrollBatch $payrollBatch,
        array $data,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): PayrollBatch {
        return DB::transaction(function () use ($payrollBatch, $data, $actor, $ipAddress, $userAgent): PayrollBatch {
            $settings = $this->companySettingService->current();
            $payrollBatch = PayrollBatch::query()->whereKey($payrollBatch->id)->lockForUpdate()->firstOrFail();
            $payrollBatch->load([
                'items' => fn ($query) => $query->orderBy('id')->with('employee:id,join_date'),
            ]);

            if ($payrollBatch->status === 'approved') {
                throw ApiException::unprocessable('Approved payroll batches cannot be edited.');
            }

            $oldValues = $this->auditBatchAttributes($this->loadBatchRelations($payrollBatch));
            $itemsById = $payrollBatch->items->keyBy('id');
            $periodEnd = Carbon::create($payrollBatch->year, $payrollBatch->month, 1)->endOfMonth()->startOfDay();

            foreach ((array) ($data['items'] ?? []) as $itemPayload) {
                /** @var PayrollItem $item */
                $item = $itemsById->get((int) $itemPayload['id']);

                if (! $item) {
                    continue;
                }

                $recalculated = $this->recalculateManualItem(
                    currentItem: $item,
                    overrides: $itemPayload,
                    settings: $settings,
                    periodEnd: $periodEnd,
                );

                $item->update(array_merge($recalculated, [
                    'status' => 'draft',
                ]));
            }

            $payrollBatch->forceFill([
                'status' => 'draft',
                'submitted_by' => null,
                'submitted_at' => null,
                'approved_by' => null,
                'approved_at' => null,
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ])->save();

            $payrollBatch = $this->loadBatchRelations($payrollBatch->fresh());

            $this->auditLogService->log(
                action: 'edit',
                module: 'payrolls',
                user: $actor,
                subject: $payrollBatch,
                oldValues: $oldValues,
                newValues: $this->auditBatchAttributes($payrollBatch),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $payrollBatch;
        });
    }

    public function submit(
        PayrollBatch $payrollBatch,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): PayrollBatch {
        return DB::transaction(function () use ($payrollBatch, $actor, $ipAddress, $userAgent): PayrollBatch {
            $payrollBatch = PayrollBatch::query()->whereKey($payrollBatch->id)->lockForUpdate()->firstOrFail();
            $payrollBatch->load('items');

            if ($payrollBatch->status !== 'draft') {
                throw ApiException::unprocessable('Only draft payroll batches can be submitted.');
            }

            if ($payrollBatch->items->isEmpty()) {
                throw ApiException::unprocessable('Payroll batch has no items to submit.');
            }

            $oldValues = $this->auditBatchAttributes($this->loadBatchRelations($payrollBatch));

            $payrollBatch->forceFill([
                'status' => 'pending_approval',
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                'approved_by' => null,
                'approved_at' => null,
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ])->save();

            $payrollBatch = $this->loadBatchRelations($payrollBatch->fresh());

            $this->auditLogService->log(
                action: 'submit',
                module: 'payrolls',
                user: $actor,
                subject: $payrollBatch,
                oldValues: $oldValues,
                newValues: $this->auditBatchAttributes($payrollBatch),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $payrollBatch;
        });
    }

    public function approve(
        PayrollBatch $payrollBatch,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): PayrollBatch {
        return DB::transaction(function () use ($payrollBatch, $actor, $ipAddress, $userAgent): PayrollBatch {
            $payrollBatch = PayrollBatch::query()->whereKey($payrollBatch->id)->lockForUpdate()->firstOrFail();
            $payrollBatch->load('items');

            if ($payrollBatch->status !== 'pending_approval') {
                throw ApiException::unprocessable('Only submitted payroll batches can be approved.');
            }

            $oldValues = $this->auditBatchAttributes($this->loadBatchRelations($payrollBatch));

            $payrollBatch->forceFill([
                'status' => 'approved',
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ])->save();

            $payrollBatch->items()->update([
                'status' => 'locked',
            ]);

            $payrollBatch = $this->loadBatchRelations($payrollBatch->fresh());

            $this->auditLogService->log(
                action: 'approve',
                module: 'payrolls',
                user: $actor,
                subject: $payrollBatch,
                oldValues: $oldValues,
                newValues: $this->auditBatchAttributes($payrollBatch),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $payrollBatch;
        });
    }

    public function reject(
        PayrollBatch $payrollBatch,
        User $actor,
        string $rejectionReason,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): PayrollBatch {
        return DB::transaction(function () use ($payrollBatch, $actor, $rejectionReason, $ipAddress, $userAgent): PayrollBatch {
            $payrollBatch = PayrollBatch::query()->whereKey($payrollBatch->id)->lockForUpdate()->firstOrFail();
            $payrollBatch->load('items');

            if ($payrollBatch->status !== 'pending_approval') {
                throw ApiException::unprocessable('Only submitted payroll batches can be rejected.');
            }

            $oldValues = $this->auditBatchAttributes($this->loadBatchRelations($payrollBatch));

            $payrollBatch->forceFill([
                'status' => 'rejected',
                'rejected_by' => $actor->id,
                'rejected_at' => now(),
                'rejection_reason' => $rejectionReason,
                'approved_by' => null,
                'approved_at' => null,
            ])->save();

            $payrollBatch->items()->update([
                'status' => 'draft',
            ]);

            $payrollBatch = $this->loadBatchRelations($payrollBatch->fresh());

            $this->auditLogService->log(
                action: 'reject',
                module: 'payrolls',
                user: $actor,
                subject: $payrollBatch,
                oldValues: $oldValues,
                newValues: $this->auditBatchAttributes($payrollBatch),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $payrollBatch;
        });
    }

    public function loadBatchRelations(PayrollBatch $payrollBatch): PayrollBatch
    {
        return $payrollBatch->load([
            'generatedBy:id,name,email',
            'submittedBy:id,name,email',
            'approvedBy:id,name,email',
            'rejectedBy:id,name,email',
            'items' => fn ($query) => $query
                ->with('employee:id,user_id,employee_id,full_name')
                ->orderBy('employee_id'),
        ])->loadCount('items')
            ->loadSum('items as total_gross_salary', 'gross_salary')
            ->loadSum('items as total_unpaid_deduction', 'unpaid_deduction')
            ->loadSum('items as total_absence_deduction', 'absence_deduction')
            ->loadSum('items as total_tax_amount', 'tax_amount')
            ->loadSum('items as total_nssf_deduction', 'nssf_deduction')
            ->loadSum('items as total_net_salary', 'net_salary');
    }

    /**
     * @param  Collection<int, mixed>  $attendanceRows
     * @param  Collection<int, LeaveRequest>  $leaveRows
     * @param  array<string, true>  $holidayDates
     * @return array<string, float|string>
     */
    protected function buildGeneratedItemPayload(
        Employee $employee,
        CompanySetting $settings,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        array $holidayDates,
        Collection $attendanceRows,
        Collection $leaveRows,
    ): array {
        $activeRange = $this->activeRangeForEmployee($employee, $periodStart, $periodEnd);

        if (! $activeRange) {
            return $this->calculatedItemPayload(
                baseSalary: (float) $employee->base_salary,
                workingDays: 0,
                presentDays: 0,
                absentDays: 0,
                unpaidLeaveDays: 0,
                maternityLeaveDays: 0,
                maternityDeductionRate: $this->maternityDeductionRate($employee->join_date, $periodEnd),
                settings: $settings,
            );
        }

        $workingDays = $this->countWorkingDays(
            $activeRange['start'],
            $activeRange['end'],
            $settings,
            $holidayDates,
        );
        $presentDays = $this->countPresentDays(
            $attendanceRows,
            $activeRange['start'],
            $activeRange['end'],
            $settings,
            $holidayDates,
        );
        $leaveBreakdown = $this->leaveBreakdown(
            $leaveRows,
            $activeRange['start'],
            $activeRange['end'],
            $settings,
            $holidayDates,
        );
        $absentDays = max(0, $workingDays - $presentDays - $leaveBreakdown['paid_leave_days'] - $leaveBreakdown['unpaid_leave_days'] - $leaveBreakdown['maternity_leave_days']);

        return $this->calculatedItemPayload(
            baseSalary: (float) $employee->base_salary,
            workingDays: $workingDays,
            presentDays: $presentDays,
            absentDays: $absentDays,
            unpaidLeaveDays: $leaveBreakdown['unpaid_leave_days'],
            maternityLeaveDays: $leaveBreakdown['maternity_leave_days'],
            maternityDeductionRate: $this->maternityDeductionRate($employee->join_date, $periodEnd),
            settings: $settings,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, float|string>
     */
    protected function recalculateManualItem(
        PayrollItem $currentItem,
        array $overrides,
        CompanySetting $settings,
        CarbonInterface $periodEnd,
    ): array {
        return $this->calculatedItemPayload(
            baseSalary: (float) ($overrides['base_salary'] ?? $currentItem->base_salary),
            workingDays: (float) ($overrides['working_days'] ?? $currentItem->working_days),
            presentDays: (float) ($overrides['present_days'] ?? $currentItem->present_days),
            absentDays: (float) ($overrides['absent_days'] ?? $currentItem->absent_days),
            unpaidLeaveDays: (float) ($overrides['unpaid_leave_days'] ?? $currentItem->unpaid_leave_days),
            maternityLeaveDays: (float) ($overrides['maternity_leave_days'] ?? $currentItem->maternity_leave_days),
            maternityDeductionRate: $this->maternityDeductionRate($currentItem->employee?->join_date, $periodEnd),
            settings: $settings,
        );
    }

    /**
     * @return array<string, float|string>
     */
    protected function calculatedItemPayload(
        float $baseSalary,
        float $workingDays,
        float $presentDays,
        float $absentDays,
        float $unpaidLeaveDays,
        float $maternityLeaveDays,
        float $maternityDeductionRate,
        CompanySetting $settings,
    ): array {
        $baseSalarySnapshot = $this->calculateBaseSalary(
            monthlyBaseSalary: $baseSalary,
            workingDays: $workingDays,
            payrollDayRate: (int) $settings->payroll_day_rate,
        );
        $deductions = $this->calculateDeductions(
            dailyRate: $baseSalarySnapshot['calculation_daily_rate'],
            grossSalary: $baseSalarySnapshot['gross_salary'],
            unpaidLeaveDays: $unpaidLeaveDays,
            absentDays: $absentDays,
            maternityLeaveDays: $maternityLeaveDays,
            maternityDeductionRate: $maternityDeductionRate,
        );
        $tax = $this->calculateTax($deductions['taxable_salary']);
        $nssfDeduction = $this->calculateNssfDeduction($deductions['taxable_salary']);
        $netSalary = $this->calculateNetSalary(
            taxableSalary: $deductions['taxable_salary'],
            taxAmount: $tax['tax_amount'],
            nssfDeduction: $nssfDeduction,
        );

        return [
            'base_salary' => $baseSalarySnapshot['base_salary'],
            'daily_rate' => $baseSalarySnapshot['daily_rate'],
            'working_days' => $this->roundMetric($workingDays),
            'present_days' => $this->roundMetric($presentDays),
            'absent_days' => $this->roundMetric($absentDays),
            'unpaid_leave_days' => $this->roundMetric($unpaidLeaveDays),
            'maternity_leave_days' => $this->roundMetric($maternityLeaveDays),
            'gross_salary' => $baseSalarySnapshot['gross_salary'],
            'unpaid_deduction' => $deductions['unpaid_deduction'],
            'absence_deduction' => $deductions['absence_deduction'],
            'maternity_deduction' => $deductions['maternity_deduction'],
            'taxable_salary' => $deductions['taxable_salary'],
            'tax_rate' => $tax['tax_rate'],
            'tax_amount' => $tax['tax_amount'],
            'nssf_deduction' => $nssfDeduction,
            'tax_breakdown' => $tax['tax_breakdown'],
            'net_salary' => $netSalary,
        ];
    }

    /**
     * @param  array<int, int>  $employeeIds
     * @return Collection<int, Collection<int, Attendance>>
     */
    protected function attendanceRowsByEmployee(
        array $employeeIds,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
    ): Collection {
        return Attendance::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('attendance_date', '>=', $periodStart->toDateString())
            ->whereDate('attendance_date', '<=', $periodEnd->toDateString())
            ->get(['employee_id', 'attendance_date', 'status'])
            ->groupBy('employee_id');
    }

    /**
     * @param  array<int, int>  $employeeIds
     * @return Collection<int, Collection<int, LeaveRequest>>
     */
    protected function approvedLeavesByEmployee(
        array $employeeIds,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
    ): Collection {
        return LeaveRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $periodEnd->toDateString())
            ->whereDate('end_date', '>=', $periodStart->toDateString())
            ->get(['employee_id', 'leave_type', 'start_date', 'end_date', 'duration_type', 'total_days'])
            ->groupBy('employee_id');
    }

    /**
     * @return array{start: CarbonInterface, end: CarbonInterface}|null
     */
    protected function activeRangeForEmployee(
        Employee $employee,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
    ): ?array {
        $start = $employee->join_date && $employee->join_date->greaterThan($periodStart)
            ? $employee->join_date->copy()->startOfDay()
            : $periodStart->copy()->startOfDay();
        $lastWorkingDate = $employee->last_working_date?->copy()->startOfDay() ?? $periodEnd->copy()->startOfDay();
        $end = $lastWorkingDate->lessThan($periodEnd)
            ? $lastWorkingDate
            : $periodEnd->copy()->startOfDay();

        if ($start->gt($end)) {
            return null;
        }

        return [
            'start' => $start,
            'end' => $end,
        ];
    }

    /**
     * @param  array<string, true>  $holidayDates
     */
    protected function countWorkingDays(
        CarbonInterface $start,
        CarbonInterface $end,
        CompanySetting $settings,
        array $holidayDates,
    ): float {
        $days = 0.0;
        $cursor = $start->copy()->startOfDay();
        $rangeEnd = $end->copy()->startOfDay();

        while ($cursor->lte($rangeEnd)) {
            if ($this->isWorkingPayrollDate($cursor, $settings, $holidayDates)) {
                $days++;
            }

            $cursor->addDay();
        }

        return $days;
    }

    /**
     * @param  Collection<int, Attendance>  $attendanceRows
     * @param  array<string, true>  $holidayDates
     */
    protected function countPresentDays(
        Collection $attendanceRows,
        CarbonInterface $start,
        CarbonInterface $end,
        CompanySetting $settings,
        array $holidayDates,
    ): float {
        return (float) $attendanceRows
            ->filter(function (Attendance $attendance) use ($start, $end, $settings, $holidayDates): bool {
                return $attendance->attendance_date
                    && $attendance->attendance_date->betweenIncluded($start, $end)
                    && in_array($attendance->status, ['present', 'late', 'missing_clock_out'], true)
                    && $this->isWorkingPayrollDate($attendance->attendance_date, $settings, $holidayDates);
            })
            ->count();
    }

    /**
     * @param  Collection<int, LeaveRequest>  $leaveRows
     * @param  array<string, true>  $holidayDates
     * @return array{paid_leave_days: float, unpaid_leave_days: float, maternity_leave_days: float}
     */
    protected function leaveBreakdown(
        Collection $leaveRows,
        CarbonInterface $start,
        CarbonInterface $end,
        CompanySetting $settings,
        array $holidayDates,
    ): array {
        $paidLeaveDays = 0.0;
        $unpaidLeaveDays = 0.0;
        $maternityLeaveDays = 0.0;

        foreach ($leaveRows as $leave) {
            if (! $leave->start_date || ! $leave->end_date) {
                continue;
            }

            $segmentStart = $leave->start_date->greaterThan($start)
                ? $leave->start_date->copy()->startOfDay()
                : $start->copy()->startOfDay();
            $segmentEnd = $leave->end_date->lessThan($end)
                ? $leave->end_date->copy()->startOfDay()
                : $end->copy()->startOfDay();

            if ($segmentStart->gt($segmentEnd)) {
                continue;
            }

            $days = $this->leaveDaysForRange(
                start: $segmentStart,
                end: $segmentEnd,
                durationType: $leave->duration_type,
                settings: $settings,
                holidayDates: $holidayDates,
            );

            if ($leave->leave_type === 'unpaid') {
                $unpaidLeaveDays += $days;
            } elseif ($leave->leave_type === 'maternity') {
                $maternityLeaveDays += $days;
            } else {
                $paidLeaveDays += $days;
            }
        }

        return [
            'paid_leave_days' => $this->roundMetric($paidLeaveDays),
            'unpaid_leave_days' => $this->roundMetric($unpaidLeaveDays),
            'maternity_leave_days' => $this->roundMetric($maternityLeaveDays),
        ];
    }

    /**
     * @param  array<string, true>  $holidayDates
     */
    protected function leaveDaysForRange(
        CarbonInterface $start,
        CarbonInterface $end,
        string $durationType,
        CompanySetting $settings,
        array $holidayDates,
    ): float {
        $days = $this->countWorkingDays($start, $end, $settings, $holidayDates);

        if ($days === 0.0) {
            return 0.0;
        }

        return $durationType === 'half_day' ? 0.5 : $days;
    }

    /**
     * @return array<string, true>
     */
    protected function holidayDates(CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        return array_fill_keys(
            PublicHoliday::query()
                ->where('status', 'active')
                ->whereDate('holiday_date', '>=', $periodStart->toDateString())
                ->whereDate('holiday_date', '<=', $periodEnd->toDateString())
                ->pluck('holiday_date')
                ->map(fn (mixed $date) => $date instanceof CarbonInterface ? $date->toDateString() : (string) $date)
                ->filter()
                ->all(),
            true,
        );
    }

    /**
     * @param  array<string, true>  $holidayDates
     */
    protected function isWorkingPayrollDate(
        CarbonInterface $date,
        CompanySetting $settings,
        array $holidayDates,
    ): bool {
        return in_array(strtolower($date->format('l')), $settings->working_days ?? [], true)
            && ! array_key_exists($date->toDateString(), $holidayDates);
    }

    /**
     * @return array{base_salary: float, daily_rate: float, calculation_daily_rate: float, gross_salary: float}
     */
    protected function calculateBaseSalary(
        float $monthlyBaseSalary,
        float $workingDays,
        int $payrollDayRate,
    ): array {
        $baseSalary = $this->roundMoney($monthlyBaseSalary);
        $calculationDailyRate = $baseSalary / max(1, $payrollDayRate);
        $dailyRate = $this->roundMoney($calculationDailyRate);

        return [
            'base_salary' => $baseSalary,
            'daily_rate' => $dailyRate,
            'calculation_daily_rate' => $calculationDailyRate,
            'gross_salary' => $this->roundMoney($calculationDailyRate * $workingDays),
        ];
    }

    /**
     * @return array{unpaid_deduction: float, absence_deduction: float, maternity_deduction: float, taxable_salary: float}
     */
    protected function calculateDeductions(
        float $dailyRate,
        float $grossSalary,
        float $unpaidLeaveDays,
        float $absentDays,
        float $maternityLeaveDays,
        float $maternityDeductionRate,
    ): array {
        $unpaidDeduction = $this->roundMoney($dailyRate * $unpaidLeaveDays);
        $absenceDeduction = $this->roundMoney($dailyRate * $absentDays);
        $maternityDeduction = $this->roundMoney($dailyRate * $maternityDeductionRate * $maternityLeaveDays);

        return [
            'unpaid_deduction' => $unpaidDeduction,
            'absence_deduction' => $absenceDeduction,
            'maternity_deduction' => $maternityDeduction,
            'taxable_salary' => $this->roundMoney(max(0, $grossSalary - $unpaidDeduction - $absenceDeduction - $maternityDeduction)),
        ];
    }

    /**
     * Employees with at least 1 year of service receive 50% pay during maternity leave.
     * Employees with less than 1 year of service are unpaid for maternity leave days.
     */
    protected function maternityDeductionRate(?CarbonInterface $joinDate, CarbonInterface $referenceDate): float
    {
        if (! $joinDate) {
            return 1.0;
        }

        return $joinDate->diffInYears($referenceDate) >= 1 ? 0.5 : 1.0;
    }

    /**
     * @return array{tax_rate: float, tax_amount: float, tax_breakdown: array<int, array<string, float|string|null>>}
     */
    protected function calculateTax(float $taxableSalary): array
    {
        if ($taxableSalary <= 0) {
            return [
                'tax_rate' => 0.0,
                'tax_amount' => 0.0,
                'tax_breakdown' => [],
            ];
        }

        /** @var Collection<int, array{up_to: float|null, rate: float}> $brackets */
        $brackets = collect((array) config('hr.payroll.tax_brackets', []))
            ->map(function (array $bracket): array {
                return [
                    'up_to' => array_key_exists('up_to', $bracket) && $bracket['up_to'] !== null
                        ? (float) $bracket['up_to']
                        : null,
                    'rate' => (float) ($bracket['rate'] ?? 0),
                ];
            })
            ->sortBy(fn (array $bracket) => $bracket['up_to'] ?? PHP_FLOAT_MAX)
            ->values();

        if ($brackets->isEmpty()) {
            return [
                'tax_rate' => 0.0,
                'tax_amount' => 0.0,
                'tax_breakdown' => [],
            ];
        }

        $tax = 0.0;
        $previousCap = 0.0;
        $breakdown = [];

        foreach ($brackets as $bracket) {
            $currentCap = $bracket['up_to'];
            $rate = $bracket['rate'];

            if ($currentCap === null) {
                $taxableSegment = max(0, $taxableSalary - $previousCap);

                $tax += $taxableSegment * $rate;
                $breakdown[] = $this->taxBreakdownRow(
                    from: $previousCap,
                    to: null,
                    rate: $rate,
                    taxableAmount: $taxableSegment,
                );

                break;
            }

            if ($taxableSalary <= $previousCap) {
                break;
            }

            $taxableSegment = min($taxableSalary, $currentCap) - $previousCap;

            if ($taxableSegment > 0) {
                $tax += $taxableSegment * $rate;
                $breakdown[] = $this->taxBreakdownRow(
                    from: $previousCap,
                    to: $currentCap,
                    rate: $rate,
                    taxableAmount: $taxableSegment,
                );
            }

            $previousCap = $currentCap;
        }

        $taxAmount = $this->roundMoney($tax);

        return [
            'tax_rate' => $taxableSalary > 0 ? round($taxAmount / $taxableSalary, 4) : 0.0,
            'tax_amount' => min($this->roundMoney($taxableSalary), $taxAmount),
            'tax_breakdown' => $breakdown,
        ];
    }

    protected function calculateNssfDeduction(float $taxableSalary): float
    {
        if ($taxableSalary <= 0) {
            return 0.0;
        }

        $threshold = (float) config('hr.payroll.nssf.salary_threshold', 300);
        $lowerDeduction = (float) config('hr.payroll.nssf.lower_deduction', 4);
        $higherDeduction = (float) config('hr.payroll.nssf.higher_deduction', 6);

        return $this->roundMoney($taxableSalary < $threshold ? $lowerDeduction : $higherDeduction);
    }

    protected function calculateNetSalary(float $taxableSalary, float $taxAmount, float $nssfDeduction): float
    {
        return $this->roundMoney(max(0, $taxableSalary - $taxAmount - $nssfDeduction));
    }

    /**
     * @return array<string, mixed>
     */
    protected function auditBatchAttributes(PayrollBatch $payrollBatch): array
    {
        $payrollBatch->loadMissing([
            'items',
        ]);

        return [
            'month' => $payrollBatch->month,
            'year' => $payrollBatch->year,
            'status' => $payrollBatch->status,
            'item_count' => $payrollBatch->items->count(),
            'employee_ids' => $payrollBatch->items->pluck('employee_id')->values()->all(),
            'generated_by' => $payrollBatch->generated_by,
            'generated_at' => $payrollBatch->generated_at?->toISOString(),
            'submitted_by' => $payrollBatch->submitted_by,
            'submitted_at' => $payrollBatch->submitted_at?->toISOString(),
            'approved_by' => $payrollBatch->approved_by,
            'approved_at' => $payrollBatch->approved_at?->toISOString(),
            'rejected_by' => $payrollBatch->rejected_by,
            'rejected_at' => $payrollBatch->rejected_at?->toISOString(),
            'rejection_reason' => $payrollBatch->rejection_reason,
        ];
    }

    protected function isDuplicatePayrollException(QueryException $exception): bool
    {
        $errorInfo = $exception->errorInfo;

        return str_contains(strtolower($exception->getMessage()), 'payroll_batches')
            && (
                ($errorInfo[0] ?? null) === '23000'
                || ($errorInfo[0] ?? null) === '23505'
                || ($exception->getCode() === '23000')
            );
    }

    protected function roundMoney(float $value): float
    {
        return round($value, 2);
    }

    protected function roundMetric(float $value): float
    {
        return round($value, 2);
    }

    /**
     * @return array{bracket: string, from: string, to: string|null, rate: string, taxable_amount: string, tax_amount: string}
     */
    protected function taxBreakdownRow(float $from, ?float $to, float $rate, float $taxableAmount): array
    {
        $taxAmount = $this->roundMoney($taxableAmount * $rate);

        return [
            'bracket' => $to === null
                ? sprintf('%.2f+ @ %.2f%%', $from, $rate * 100)
                : sprintf('%.2f - %.2f @ %.2f%%', $from, $to, $rate * 100),
            'from' => $this->formatMoney($from),
            'to' => $to === null ? null : $this->formatMoney($to),
            'rate' => number_format($rate, 4, '.', ''),
            'taxable_amount' => $this->formatMoney($taxableAmount),
            'tax_amount' => $this->formatMoney($taxAmount),
        ];
    }

    protected function formatMoney(float $value): string
    {
        return number_format($this->roundMoney($value), 2, '.', '');
    }
}
