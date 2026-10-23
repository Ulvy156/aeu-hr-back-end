<?php

namespace App\Services\Reports;

use App\Enums\Status;
use App\Exports\ArrayReportExport;
use App\Models\Attendance;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PublicHoliday;
use App\Services\CompanySettingService;
use App\Support\FileStorage;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class MonthlyLeaveReportService
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
        $month = (int) ($filters['month'] ?? now()->month);
        $year = (int) ($filters['year'] ?? now()->year);
        $perPage = (int) ($filters['per_page'] ?? 15);

        $period = $this->period($month, $year);
        $settings = $this->companySettingService->current();
        $holidayDates = $this->holidayDates($period['year_start'], $period['month_end']);

        $paginator = $this->employeeQuery($period['month_start'], $period['month_end'])->paginate($perPage);
        $rows = $this->buildRows(
            employees: collect($paginator->items()),
            period: $period,
            settings: $settings,
            holidayDates: $holidayDates,
            startNo: ($paginator->currentPage() - 1) * $paginator->perPage() + 1,
        );

        $summary = $this->buildSummary($period, $settings, $holidayDates);

        return [
            'report_type' => 'monthly_leave',
            'paginated' => true,
            'resource' => 'summary',
            'paginator' => $paginator->setCollection($rows),
            'summary' => $summary,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{file_name: string, export: ArrayReportExport}
     */
    public function export(array $filters): array
    {
        $month = (int) ($filters['month'] ?? now()->month);
        $year = (int) ($filters['year'] ?? now()->year);
        $period = $this->period($month, $year);
        $settings = $this->companySettingService->current();
        $holidayDates = $this->holidayDates($period['year_start'], $period['month_end']);
        $monthLabel = Carbon::create($year, $month, 1)->format('M');

        $rows = $this->buildRows(
            employees: $this->employeeQuery($period['month_start'], $period['month_end'])->get(),
            period: $period,
            settings: $settings,
            holidayDates: $holidayDates,
            startNo: 1,
        )->map(fn (array $row): array => [
            $row['no'],
            $row['name'],
            $row['position'],
            $row['gender'],
            $row['absence_days'],
            $row['leave_days'],
            $row['reason'],
        ])->all();

        $monthPadded = str_pad((string) $month, 2, '0', STR_PAD_LEFT);

        return [
            'file_name' => "monthly-leave-{$year}-{$monthPadded}.xlsx",
            'export' => new ArrayReportExport(
                headings: [
                    'No.',
                    'Name',
                    'Position',
                    'Gender',
                    'Absence',
                    "Leave on {$monthLabel}",
                    'Date / Reason',
                ],
                rows: $rows,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function pdf(array $filters): Response
    {
        $month = (int) ($filters['month'] ?? now()->month);
        $year = (int) ($filters['year'] ?? now()->year);
        $period = $this->period($month, $year);
        $settings = $this->companySettingService->current();
        $holidayDates = $this->holidayDates($period['year_start'], $period['month_end']);
        $periodStart = Carbon::create($year, $month, 1);

        $rows = $this->buildRows(
            employees: $this->employeeQuery($period['month_start'], $period['month_end'])->get(),
            period: $period,
            settings: $settings,
            holidayDates: $holidayDates,
            startNo: 1,
        )->all();

        $monthPadded = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
        $fileName = "monthly-leave-{$year}-{$monthPadded}.pdf";

        $pdf = Pdf::loadView('reports.monthly-leave', [
            'companySetting' => $settings,
            'companyLogoDataUri' => $this->companyLogoDataUri($settings->company_logo)
                ?? $this->defaultCompanyLogoDataUri(),
            'periodLabel' => $periodStart->format('F Y'),
            'leaveColumnLabel' => 'Leave on '.$periodStart->format('M'),
            'generatedDateLabel' => now()->format('j F Y'),
            'rows' => $rows,
        ])
            ->setPaper('a4', 'portrait')
            ->setOption('chroot', base_path())
            ->setOption('defaultFont', 'NotoSansKhmer')
            ->setOption('isFontSubsettingEnabled', true);

        return $pdf->download($fileName);
    }

    /**
     * @return array{
     *   month_start: CarbonInterface,
     *   month_end: CarbonInterface,
     *   year_start: CarbonInterface,
     *   month: int,
     *   year: int
     * }
     */
    protected function period(int $month, int $year): array
    {
        $monthStart = Carbon::create($year, $month, 1)->startOfDay();

        return [
            'month_start' => $monthStart,
            'month_end' => $monthStart->copy()->endOfMonth()->startOfDay(),
            'year_start' => Carbon::create($year, 1, 1)->startOfDay(),
            'month' => $month,
            'year' => $year,
        ];
    }

    protected function employeeQuery(CarbonInterface $monthStart, CarbonInterface $monthEnd): Builder
    {
        return Employee::query()
            ->with(['position:id,name'])
            ->whereDate('join_date', '<=', $monthEnd->toDateString())
            ->where(function (Builder $query) use ($monthStart): void {
                $query->whereNull('last_working_date')
                    ->orWhereDate('last_working_date', '>=', $monthStart->toDateString());
            })
            ->orderBy('full_name')
            ->orderBy('id');
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @param  array{
     *   month_start: CarbonInterface,
     *   month_end: CarbonInterface,
     *   year_start: CarbonInterface,
     *   month: int,
     *   year: int
     * }  $period
     * @param  array<string, true>  $holidayDates
     * @return Collection<int, array<string, mixed>>
     */
    protected function buildRows(
        Collection $employees,
        array $period,
        CompanySetting $settings,
        array $holidayDates,
        int $startNo,
    ): Collection {
        if ($employees->isEmpty()) {
            return collect();
        }

        $employeeIds = $employees->pluck('id')->all();
        $workingDays = $settings->working_days ?? [];

        $approvedLeaves = LeaveRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $period['month_end']->toDateString())
            ->whereDate('end_date', '>=', $period['month_start']->toDateString())
            ->get([
                'employee_id',
                'leave_type',
                'start_date',
                'end_date',
                'duration_type',
                'reason',
                'total_days',
            ])
            ->groupBy('employee_id');

        $absences = Attendance::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'absent')
            ->whereDate('attendance_date', '>=', $period['month_start']->toDateString())
            ->whereDate('attendance_date', '<=', $period['month_end']->toDateString())
            ->get(['employee_id', 'attendance_date', 'status', 'absent_periods'])
            ->groupBy('employee_id');

        $no = $startNo;

        return $employees->map(function (Employee $employee) use (
            &$no,
            $period,
            $workingDays,
            $holidayDates,
            $approvedLeaves,
            $absences,
        ): array {
            $leaveRows = $approvedLeaves->get($employee->id, collect());
            $absenceRows = $absences->get($employee->id, collect());

            $leaveDays = $this->leaveDaysInRange(
                $leaveRows,
                $period['month_start'],
                $period['month_end'],
                $workingDays,
                $holidayDates,
            );
            $absenceDays = $this->absenceDays($absenceRows);
            $monthLeaves = $this->leavesOverlappingRange(
                $leaveRows,
                $period['month_start'],
                $period['month_end'],
            );

            return [
                'no' => $no++,
                'name' => $employee->full_name,
                'position' => $employee->position?->name ?? '—',
                'gender' => $this->formatGender($employee->gender),
                'absence_days' => $this->formatDecimal($absenceDays),
                'leave_days' => $this->formatDecimal($leaveDays),
                'reason' => $this->formatLeaveDateReasons(
                    $monthLeaves,
                    $period['month_start'],
                    $period['month_end'],
                    $workingDays,
                    $holidayDates,
                ),
                'employee' => [
                    'id' => $employee->id,
                    'employee_id' => $employee->employee_id,
                    'full_name' => $employee->full_name,
                ],
            ];
        })->values();
    }

    /**
     * @param  array{
     *   month_start: CarbonInterface,
     *   month_end: CarbonInterface,
     *   year_start: CarbonInterface,
     *   month: int,
     *   year: int
     * }  $period
     * @param  array<string, true>  $holidayDates
     * @return array<string, mixed>
     */
    protected function buildSummary(array $period, CompanySetting $settings, array $holidayDates): array
    {
        $employees = $this->employeeQuery($period['month_start'], $period['month_end'])->get();
        $rows = $this->buildRows($employees, $period, $settings, $holidayDates, 1);

        $absenceTotal = $rows->sum(fn (array $row): float => (float) $row['absence_days']);
        $leaveTotal = $rows->sum(fn (array $row): float => (float) $row['leave_days']);

        return [
            'employee_count' => $employees->count(),
            'absence_days' => $this->formatDecimal($absenceTotal),
            'leave_days' => $this->formatDecimal($leaveTotal),
            'month' => $period['month'],
            'year' => $period['year'],
            'leave_column_label' => 'Leave on '.Carbon::create($period['year'], $period['month'], 1)->format('M'),
        ];
    }

    /**
     * @param  Collection<int, LeaveRequest>  $leaveRows
     * @param  array<int, string>  $workingDays
     * @param  array<string, true>  $holidayDates
     */
    protected function leaveDaysInRange(
        Collection $leaveRows,
        CarbonInterface $rangeStart,
        CarbonInterface $rangeEnd,
        array $workingDays,
        array $holidayDates,
    ): float {
        return (float) $leaveRows->sum(function (LeaveRequest $leave) use ($rangeStart, $rangeEnd, $workingDays, $holidayDates): float {
            if (! $leave->start_date || ! $leave->end_date) {
                return 0.0;
            }

            $segmentStart = $leave->start_date->greaterThan($rangeStart)
                ? $leave->start_date->copy()->startOfDay()
                : $rangeStart->copy()->startOfDay();
            $segmentEnd = $leave->end_date->lessThan($rangeEnd)
                ? $leave->end_date->copy()->startOfDay()
                : $rangeEnd->copy()->startOfDay();

            if ($segmentStart->gt($segmentEnd)) {
                return 0.0;
            }

            return $this->workingLeaveDays($segmentStart, $segmentEnd, (string) $leave->duration_type, $workingDays, $holidayDates);
        });
    }

    /**
     * @param  Collection<int, LeaveRequest>  $leaveRows
     * @return Collection<int, LeaveRequest>
     */
    protected function leavesOverlappingRange(
        Collection $leaveRows,
        CarbonInterface $rangeStart,
        CarbonInterface $rangeEnd,
    ): Collection {
        return $leaveRows->filter(function (LeaveRequest $leave) use ($rangeStart, $rangeEnd): bool {
            if (! $leave->start_date || ! $leave->end_date) {
                return false;
            }

            return $leave->start_date->lte($rangeEnd) && $leave->end_date->gte($rangeStart);
        })->values();
    }

    /**
     * @param  Collection<int, Attendance>  $absenceRows
     */
    protected function absenceDays(Collection $absenceRows): float
    {
        return (float) $absenceRows->sum(function (Attendance $attendance): float {
            if (is_array($attendance->absent_periods) && $attendance->absent_periods !== []) {
                return count($attendance->absent_periods) * 0.5;
            }

            return 1.0;
        });
    }

    /**
     * @param  Collection<int, LeaveRequest>  $monthLeaves
     * @param  array<int, string>  $workingDays
     * @param  array<string, true>  $holidayDates
     */
    protected function formatLeaveDateReasons(
        Collection $monthLeaves,
        CarbonInterface $rangeStart,
        CarbonInterface $rangeEnd,
        array $workingDays,
        array $holidayDates,
    ): string {
        /** @var array<int, array{sort: string, label: string}> $pairs */
        $pairs = [];

        foreach ($monthLeaves as $leave) {
            if (! $leave->start_date || ! $leave->end_date) {
                continue;
            }

            $reason = trim((string) $leave->reason);
            if ($reason === '') {
                continue;
            }

            $segmentStart = $leave->start_date->greaterThan($rangeStart)
                ? $leave->start_date->copy()->startOfDay()
                : $rangeStart->copy()->startOfDay();
            $segmentEnd = $leave->end_date->lessThan($rangeEnd)
                ? $leave->end_date->copy()->startOfDay()
                : $rangeEnd->copy()->startOfDay();

            if ($segmentStart->gt($segmentEnd)) {
                continue;
            }

            $cursor = $segmentStart->copy();
            while ($cursor->lte($segmentEnd)) {
                if (
                    in_array(strtolower($cursor->format('l')), $workingDays, true)
                    && ! array_key_exists($cursor->toDateString(), $holidayDates)
                ) {
                    $dateLabel = $cursor->day.'-'.$cursor->month.'-'.$cursor->year;
                    $pairs[] = [
                        'sort' => $cursor->toDateString().'|'.$reason,
                        'label' => $dateLabel.': '.$reason,
                    ];
                }
                $cursor->addDay();
            }
        }

        if ($pairs === []) {
            return '';
        }

        usort($pairs, fn (array $a, array $b): int => strcmp($a['sort'], $b['sort']));

        $labels = [];
        $seen = [];
        foreach ($pairs as $pair) {
            if (isset($seen[$pair['label']])) {
                continue;
            }
            $seen[$pair['label']] = true;
            $labels[] = $pair['label'];
        }

        return implode('; ', $labels);
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

    protected function formatGender(?string $gender): string
    {
        return match ($gender) {
            'male' => 'M',
            'female' => 'F',
            'other' => 'Other',
            default => '—',
        };
    }

    protected function formatDecimal(float $value): string
    {
        $formatted = number_format($value, 2, '.', '');

        return rtrim(rtrim($formatted, '0'), '.') ?: '0';
    }

    protected function companyLogoDataUri(?string $companyLogoPath): ?string
    {
        if (! $companyLogoPath || ! FileStorage::disk()->exists($companyLogoPath)) {
            return null;
        }

        $contents = FileStorage::disk()->get($companyLogoPath);
        $mimeType = FileStorage::disk()->mimeType($companyLogoPath) ?: 'image/png';

        return 'data:'.$mimeType.';base64,'.base64_encode($contents);
    }

    protected function defaultCompanyLogoDataUri(): ?string
    {
        $logoPath = public_path('pdm-care-logo.png');

        if (! is_file($logoPath)) {
            return null;
        }

        $contents = file_get_contents($logoPath);

        return $contents === false ? null : 'data:image/png;base64,'.base64_encode($contents);
    }
}
