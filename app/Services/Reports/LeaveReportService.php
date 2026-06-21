<?php

namespace App\Services\Reports;

use App\Enums\Status;
use App\Exports\ArrayReportExport;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PublicHoliday;
use App\Services\CompanySettingService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class LeaveReportService
{
    public function __construct(
        protected CompanySettingService $companySettingService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function report(array $filters): array
    {
        $filters['employee_id'] = Employee::resolveId($filters['employee_id'] ?? null);
        $reportType = (string) ($filters['report_type'] ?? 'request_list');

        return match ($reportType) {
            'pending_approval' => $this->leaveListReport(array_merge($filters, ['status' => 'pending']), 'pending_approval'),
            'approved' => $this->leaveListReport(array_merge($filters, ['status' => 'approved']), 'approved'),
            'rejected' => $this->leaveListReport(array_merge($filters, ['status' => 'rejected']), 'rejected'),
            'leave_balance' => $this->leaveBalanceReport($filters),
            default => $this->leaveListReport($filters, 'request_list'),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{file_name: string, export: ArrayReportExport}
     */
    public function export(array $filters): array
    {
        $filters['employee_id'] = Employee::resolveId($filters['employee_id'] ?? null);
        $reportType = (string) ($filters['report_type'] ?? 'request_list');

        return match ($reportType) {
            'pending_approval' => $this->exportLeaveList(array_merge($filters, ['status' => 'pending']), 'pending-approval'),
            'approved' => $this->exportLeaveList(array_merge($filters, ['status' => 'approved']), 'approved'),
            'rejected' => $this->exportLeaveList(array_merge($filters, ['status' => 'rejected']), 'rejected'),
            'leave_balance' => $this->exportLeaveBalance($filters),
            default => $this->exportLeaveList($filters, 'request-list'),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function leaveListReport(array $filters, string $reportType): array
    {
        $perPage = (int) ($filters['per_page'] ?? 15);
        $query = $this->leaveListQuery($filters);
        $summary = (clone $query)
            ->reorder()
            ->selectRaw('COUNT(leave_requests.id) as total_records')
            ->selectRaw("SUM(CASE WHEN leave_requests.status = 'pending' THEN 1 ELSE 0 END) as pending_count")
            ->selectRaw("SUM(CASE WHEN leave_requests.status = 'approved' THEN 1 ELSE 0 END) as approved_count")
            ->selectRaw("SUM(CASE WHEN leave_requests.status = 'rejected' THEN 1 ELSE 0 END) as rejected_count")
            ->selectRaw("SUM(CASE WHEN leave_requests.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count")
            ->selectRaw('COALESCE(SUM(leave_requests.total_days), 0) as total_days')
            ->first();

        return [
            'report_type' => $reportType,
            'paginated' => true,
            'resource' => 'leave',
            'paginator' => $query->paginate($perPage),
            'summary' => [
                'total_records' => (int) ($summary?->total_records ?? 0),
                'pending_count' => (int) ($summary?->pending_count ?? 0),
                'approved_count' => (int) ($summary?->approved_count ?? 0),
                'rejected_count' => (int) ($summary?->rejected_count ?? 0),
                'cancelled_count' => (int) ($summary?->cancelled_count ?? 0),
                'total_days' => $this->formatDecimal((float) ($summary?->total_days ?? 0)),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function leaveBalanceReport(array $filters): array
    {
        $perPage = (int) ($filters['per_page'] ?? 15);
        $year = (int) ($filters['year'] ?? now()->year);
        $paginator = $this->leaveBalanceEmployeeQuery($filters)->paginate($perPage);
        $rows = $this->leaveBalanceRows(collect($paginator->items()), $year);

        return [
            'report_type' => 'leave_balance',
            'paginated' => true,
            'resource' => 'summary',
            'paginator' => $paginator->setCollection($rows),
            'summary' => [
                'year' => $year,
                'employee_count' => $paginator->total(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{file_name: string, export: ArrayReportExport}
     */
    protected function exportLeaveList(array $filters, string $suffix): array
    {
        $rows = $this->leaveListQuery($filters)->get()->map(function (LeaveRequest $leave): array {
            return [
                $leave->employee?->employee_id,
                $leave->employee?->full_name,
                $leave->leave_type,
                $leave->start_date?->toDateString(),
                $leave->end_date?->toDateString(),
                (string) $leave->total_days,
                $leave->status,
                $leave->hr_approval_status,
                $leave->ceo_approval_status,
                $leave->rejection_reason,
            ];
        })->all();

        return [
            'file_name' => "leave-{$suffix}-report.xlsx",
            'export' => new ArrayReportExport(
                headings: ['Employee ID', 'Employee Name', 'Leave Type', 'Start Date', 'End Date', 'Total Days', 'Status', 'HR Approval Status', 'CEO Approval Status', 'Rejection Reason'],
                rows: $rows,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{file_name: string, export: ArrayReportExport}
     */
    protected function exportLeaveBalance(array $filters): array
    {
        $year = (int) ($filters['year'] ?? now()->year);
        $rows = $this->leaveBalanceRows($this->leaveBalanceEmployeeQuery($filters)->get(), $year)
            ->map(fn (array $row): array => [
                $row['employee']['employee_id'],
                $row['employee']['full_name'],
                $row['annual']['used'],
                $row['annual']['remaining'],
                $row['sick']['used'],
                $row['sick']['remaining'],
                $row['special']['used'],
                $row['special']['remaining'],
                $row['maternity']['entitlement'],
                $row['maternity']['remaining'],
                $row['special_sick']['used'],
                $row['special_sick']['remaining'],
                $row['unpaid']['rule'],
            ])
            ->all();

        return [
            'file_name' => 'leave-balance-report.xlsx',
            'export' => new ArrayReportExport(
                headings: ['Employee ID', 'Employee Name', 'Annual Used', 'Annual Remaining', 'Sick Used', 'Sick Remaining', 'Special Used', 'Special Remaining', 'Maternity Entitlement', 'Maternity Remaining', 'Special Sick Used', 'Special Sick Remaining', 'Unpaid Rule'],
                rows: $rows,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function leaveListQuery(array $filters): Builder
    {
        return LeaveRequest::query()
            ->with([
                'employee:id,user_id,employee_id,full_name',
                'hrApprovedBy:id,name',
                'ceoApprovedBy:id,name',
            ])
            ->when($filters['employee_id'] ?? null, fn (Builder $query, int $employeeId) => $query->where('employee_id', $employeeId))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['leave_type'] ?? null, fn (Builder $query, string $leaveType) => $query->where('leave_type', $leaveType))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $dateFrom) => $query->whereDate('end_date', '>=', $dateFrom))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $dateTo) => $query->whereDate('start_date', '<=', $dateTo))
            ->orderByDesc('start_date')
            ->orderByDesc('id');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function leaveBalanceEmployeeQuery(array $filters): Builder
    {
        return Employee::query()
            ->select(['id', 'employee_id', 'full_name'])
            ->when($filters['employee_id'] ?? null, fn (Builder $query, int $employeeId) => $query->whereKey($employeeId))
            ->orderBy('employee_id');
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @return Collection<int, array<string, mixed>>
     */
    protected function leaveBalanceRows(Collection $employees, int $year): Collection
    {
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->startOfDay();
        $settings = $this->companySettingService->current();
        $holidayDates = $this->holidayDates($yearStart, $yearEnd);
        $approvedLeaves = LeaveRequest::query()
            ->whereIn('employee_id', $employees->pluck('id')->all())
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $yearEnd->toDateString())
            ->whereDate('end_date', '>=', $yearStart->toDateString())
            ->get(['employee_id', 'leave_type', 'start_date', 'end_date', 'duration_type', 'total_days'])
            ->groupBy('employee_id');

        return $employees->map(function (Employee $employee) use ($year, $yearStart, $yearEnd, $settings, $holidayDates, $approvedLeaves): array {
            $leaveRows = $approvedLeaves->get($employee->id, collect());
            $annualUsed = $this->usedDaysForLeaveType($leaveRows, 'annual', $yearStart, $yearEnd, $settings->working_days ?? [], $holidayDates);
            $sickUsed = $this->usedDaysForLeaveType($leaveRows, 'sick', $yearStart, $yearEnd, $settings->working_days ?? [], $holidayDates);
            $specialUsed = $this->usedDaysForLeaveType($leaveRows, 'special', $yearStart, $yearEnd, $settings->working_days ?? [], $holidayDates);
            $specialSickUsed = $this->usedDaysForLeaveType($leaveRows, 'special_sick', $yearStart, $yearEnd, $settings->working_days ?? [], $holidayDates);
            $maternityEntitlement = (float) config('hr.leave.entitlements.maternity', 90);
            $specialSickEntitlement = (float) config('hr.leave.entitlements.special_sick', 180);

            return [
                'employee' => [
                    'id' => $employee->id,
                    'employee_id' => $employee->employee_id,
                    'full_name' => $employee->full_name,
                ],
                'year' => $year,
                'annual' => [
                    'entitlement' => $this->formatDecimal((float) config('hr.leave.entitlements.annual', 0)),
                    'used' => $this->formatDecimal($annualUsed),
                    'remaining' => $this->formatDecimal(max(0, (float) config('hr.leave.entitlements.annual', 0) - $annualUsed)),
                ],
                'sick' => [
                    'entitlement' => $this->formatDecimal((float) config('hr.leave.entitlements.sick', 0)),
                    'used' => $this->formatDecimal($sickUsed),
                    'remaining' => $this->formatDecimal(max(0, (float) config('hr.leave.entitlements.sick', 0) - $sickUsed)),
                ],
                'special' => [
                    'entitlement' => $this->formatDecimal((float) config('hr.leave.entitlements.special', 0)),
                    'used' => $this->formatDecimal($specialUsed),
                    'remaining' => $this->formatDecimal(max(0, (float) config('hr.leave.entitlements.special', 0) - $specialUsed)),
                ],
                'maternity' => [
                    'entitlement' => $this->formatDecimal($maternityEntitlement),
                    'remaining' => $this->formatDecimal($maternityEntitlement),
                ],
                'special_sick' => [
                    'entitlement' => $this->formatDecimal($specialSickEntitlement),
                    'used' => $this->formatDecimal($specialSickUsed),
                    'remaining' => $this->formatDecimal(max(0, $specialSickEntitlement - $specialSickUsed)),
                    'rule' => 'per_case_per_year',
                ],
                'unpaid' => [
                    'rule' => 'unlimited',
                ],
            ];
        })->values();
    }

    /**
     * @param  Collection<int, LeaveRequest>  $leaveRows
     * @param  array<string, true>  $holidayDates
     * @param  array<int, string>  $workingDays
     */
    protected function usedDaysForLeaveType(
        Collection $leaveRows,
        string $leaveType,
        CarbonInterface $yearStart,
        CarbonInterface $yearEnd,
        array $workingDays,
        array $holidayDates,
    ): float {
        return (float) $leaveRows
            ->where('leave_type', $leaveType)
            ->sum(function (LeaveRequest $leave) use ($yearStart, $yearEnd, $workingDays, $holidayDates): float {
                if (! $leave->start_date || ! $leave->end_date) {
                    return 0.0;
                }

                $segmentStart = $leave->start_date->greaterThan($yearStart) ? $leave->start_date->copy()->startOfDay() : $yearStart->copy()->startOfDay();
                $segmentEnd = $leave->end_date->lessThan($yearEnd) ? $leave->end_date->copy()->startOfDay() : $yearEnd->copy()->startOfDay();

                if ($segmentStart->gt($segmentEnd)) {
                    return 0.0;
                }

                return $this->workingLeaveDays($segmentStart, $segmentEnd, $leave->duration_type, $workingDays, $holidayDates);
            });
    }

    /**
     * @param  array<int, string>  $workingDays
     * @param  array<string, true>  $holidayDates
     */
    protected function workingLeaveDays(
        CarbonInterface $start,
        CarbonInterface $end,
        string $durationType,
        array $workingDays,
        array $holidayDates,
    ): float {
        $days = 0.0;
        $cursor = $start->copy()->startOfDay();
        $rangeEnd = $end->copy()->startOfDay();

        while ($cursor->lte($rangeEnd)) {
            if (
                in_array(strtolower($cursor->format('l')), $workingDays, true)
                && ! array_key_exists($cursor->toDateString(), $holidayDates)
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

    /**
     * @return array<string, true>
     */
    protected function holidayDates(CarbonInterface $start, CarbonInterface $end): array
    {
        return array_fill_keys(
            PublicHoliday::query()
                ->where('status', Status::Active->value)
                ->whereDate('holiday_date', '>=', $start->toDateString())
                ->whereDate('holiday_date', '<=', $end->toDateString())
                ->pluck('holiday_date')
                ->map(fn (mixed $date) => $date instanceof CarbonInterface ? $date->toDateString() : (string) $date)
                ->filter()
                ->all(),
            true,
        );
    }

    protected function formatDecimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
