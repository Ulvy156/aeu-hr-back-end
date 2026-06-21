<?php

namespace App\Services\Reports;

use App\Exports\ArrayReportExport;
use App\Models\Attendance;
use App\Models\Employee;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class AttendanceReportService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function report(array $filters): array
    {
        $filters['employee_id'] = Employee::resolveId($filters['employee_id'] ?? null);
        $reportType = (string) ($filters['report_type'] ?? 'daily_list');

        return match ($reportType) {
            'monthly_summary' => $this->monthlySummaryReport($filters),
            'late_employees' => $this->attendanceListReport(array_merge($filters, ['status' => 'late']), 'late_employees'),
            'absent_employees' => $this->attendanceListReport(array_merge($filters, ['status' => 'absent']), 'absent_employees'),
            'correction_list' => $this->correctionListReport($filters),
            default => $this->attendanceListReport($filters, 'daily_list'),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{file_name: string, export: ArrayReportExport}
     */
    public function export(array $filters): array
    {
        $filters['employee_id'] = Employee::resolveId($filters['employee_id'] ?? null);
        $reportType = (string) ($filters['report_type'] ?? 'daily_list');

        return match ($reportType) {
            'monthly_summary' => $this->exportMonthlySummary($filters),
            'late_employees' => $this->exportAttendanceList(array_merge($filters, ['status' => 'late']), 'late-employees'),
            'absent_employees' => $this->exportAttendanceList(array_merge($filters, ['status' => 'absent']), 'absent-employees'),
            'correction_list' => $this->exportCorrectionList($filters),
            default => $this->exportAttendanceList($filters, 'daily-list'),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function attendanceListReport(array $filters, string $reportType): array
    {
        $perPage = (int) ($filters['per_page'] ?? 15);
        $query = $this->attendanceListQuery($filters);
        $summary = (clone $query)
            ->reorder()
            ->selectRaw('COUNT(attendances.id) as total_records')
            ->selectRaw("SUM(CASE WHEN attendances.status = 'present' THEN 1 ELSE 0 END) as present_count")
            ->selectRaw("SUM(CASE WHEN attendances.status = 'late' THEN 1 ELSE 0 END) as late_count")
            ->selectRaw("SUM(CASE WHEN attendances.status = 'absent' THEN 1 ELSE 0 END) as absent_count")
            ->selectRaw("SUM(CASE WHEN attendances.status = 'missing_clock_out' THEN 1 ELSE 0 END) as missing_clock_out_count")
            ->selectRaw('SUM(CASE WHEN attendances.corrected_at IS NOT NULL THEN 1 ELSE 0 END) as corrected_count')
            ->first();

        return [
            'report_type' => $reportType,
            'paginated' => true,
            'resource' => 'attendance',
            'paginator' => $query->paginate($perPage),
            'summary' => [
                'total_records' => (int) ($summary?->total_records ?? 0),
                'present_count' => (int) ($summary?->present_count ?? 0),
                'late_count' => (int) ($summary?->late_count ?? 0),
                'absent_count' => (int) ($summary?->absent_count ?? 0),
                'missing_clock_out_count' => (int) ($summary?->missing_clock_out_count ?? 0),
                'corrected_count' => (int) ($summary?->corrected_count ?? 0),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function correctionListReport(array $filters): array
    {
        return $this->attendanceListReport(array_merge($filters, ['corrected_only' => true]), 'correction_list');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function monthlySummaryReport(array $filters): array
    {
        $perPage = (int) ($filters['per_page'] ?? 15);
        $period = $this->summaryPeriod($filters);
        $query = Employee::query()
            ->join('attendances', 'attendances.employee_id', '=', 'employees.id')
            ->whereDate('attendances.attendance_date', '>=', $period['start']->toDateString())
            ->whereDate('attendances.attendance_date', '<=', $period['end']->toDateString())
            ->when($filters['employee_id'] ?? null, fn (Builder $query, int $employeeId) => $query->where('employees.id', $employeeId))
            ->groupBy('employees.id', 'employees.employee_id', 'employees.full_name')
            ->orderBy('employees.employee_id')
            ->select([
                'employees.id',
                'employees.employee_id',
                'employees.full_name',
            ])
            ->selectRaw('COUNT(attendances.id) as total_records')
            ->selectRaw("SUM(CASE WHEN attendances.status = 'present' THEN 1 ELSE 0 END) as present_count")
            ->selectRaw("SUM(CASE WHEN attendances.status = 'late' THEN 1 ELSE 0 END) as late_count")
            ->selectRaw("SUM(CASE WHEN attendances.status = 'absent' THEN 1 ELSE 0 END) as absent_count")
            ->selectRaw("SUM(CASE WHEN attendances.status = 'missing_clock_out' THEN 1 ELSE 0 END) as missing_clock_out_count")
            ->selectRaw('SUM(CASE WHEN attendances.corrected_at IS NOT NULL THEN 1 ELSE 0 END) as corrected_count');

        $summary = (clone $query)
            ->get()
            ->reduce(function (array $carry, Employee $employee): array {
                $carry['employee_count']++;
                $carry['total_records'] += (int) $employee->total_records;
                $carry['present_count'] += (int) $employee->present_count;
                $carry['late_count'] += (int) $employee->late_count;
                $carry['absent_count'] += (int) $employee->absent_count;
                $carry['missing_clock_out_count'] += (int) $employee->missing_clock_out_count;
                $carry['corrected_count'] += (int) $employee->corrected_count;

                return $carry;
            }, [
                'employee_count' => 0,
                'total_records' => 0,
                'present_count' => 0,
                'late_count' => 0,
                'absent_count' => 0,
                'missing_clock_out_count' => 0,
                'corrected_count' => 0,
            ]);

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage);

        return [
            'report_type' => 'monthly_summary',
            'paginated' => true,
            'resource' => 'summary',
            'paginator' => $paginator,
            'summary' => array_merge($summary, [
                'period_start' => $period['start']->toDateString(),
                'period_end' => $period['end']->toDateString(),
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{file_name: string, export: ArrayReportExport}
     */
    protected function exportAttendanceList(array $filters, string $suffix): array
    {
        $rows = $this->attendanceListQuery($filters)->get()->map(function (Attendance $attendance): array {
            return [
                $attendance->employee?->employee_id,
                $attendance->employee?->full_name,
                $attendance->attendance_date?->toDateString(),
                $attendance->status,
                $attendance->is_late ? 'Yes' : 'No',
                $attendance->clock_in_time?->toISOString(),
                $attendance->clock_out_time?->toISOString(),
                $attendance->corrected_at?->toISOString(),
            ];
        })->all();

        return [
            'file_name' => "attendance-{$suffix}-report.xlsx",
            'export' => new ArrayReportExport(
                headings: ['Employee ID', 'Employee Name', 'Attendance Date', 'Status', 'Is Late', 'Clock In Time', 'Clock Out Time', 'Corrected At'],
                rows: $rows,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{file_name: string, export: ArrayReportExport}
     */
    protected function exportCorrectionList(array $filters): array
    {
        return $this->exportAttendanceList(array_merge($filters, ['corrected_only' => true]), 'correction-list');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{file_name: string, export: ArrayReportExport}
     */
    protected function exportMonthlySummary(array $filters): array
    {
        $period = $this->summaryPeriod($filters);
        $rows = Employee::query()
            ->join('attendances', 'attendances.employee_id', '=', 'employees.id')
            ->whereDate('attendances.attendance_date', '>=', $period['start']->toDateString())
            ->whereDate('attendances.attendance_date', '<=', $period['end']->toDateString())
            ->when($filters['employee_id'] ?? null, fn (Builder $query, int $employeeId) => $query->where('employees.id', $employeeId))
            ->groupBy('employees.id', 'employees.employee_id', 'employees.full_name')
            ->orderBy('employees.employee_id')
            ->select([
                'employees.id',
                'employees.employee_id',
                'employees.full_name',
            ])
            ->selectRaw('COUNT(attendances.id) as total_records')
            ->selectRaw("SUM(CASE WHEN attendances.status = 'present' THEN 1 ELSE 0 END) as present_count")
            ->selectRaw("SUM(CASE WHEN attendances.status = 'late' THEN 1 ELSE 0 END) as late_count")
            ->selectRaw("SUM(CASE WHEN attendances.status = 'absent' THEN 1 ELSE 0 END) as absent_count")
            ->selectRaw("SUM(CASE WHEN attendances.status = 'missing_clock_out' THEN 1 ELSE 0 END) as missing_clock_out_count")
            ->selectRaw('SUM(CASE WHEN attendances.corrected_at IS NOT NULL THEN 1 ELSE 0 END) as corrected_count')
            ->get()
            ->map(fn (Employee $employee): array => [
                $employee->employee_id,
                $employee->full_name,
                (int) $employee->total_records,
                (int) $employee->present_count,
                (int) $employee->late_count,
                (int) $employee->absent_count,
                (int) $employee->missing_clock_out_count,
                (int) $employee->corrected_count,
            ])
            ->all();

        return [
            'file_name' => 'attendance-monthly-summary-report.xlsx',
            'export' => new ArrayReportExport(
                headings: ['Employee ID', 'Employee Name', 'Total Records', 'Present Count', 'Late Count', 'Absent Count', 'Missing Clock Out Count', 'Corrected Count'],
                rows: $rows,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function attendanceListQuery(array $filters): Builder
    {
        return Attendance::query()
            ->with([
                'employee:id,user_id,employee_id,full_name',
                'correctedBy:id,name,email',
            ])
            ->when($filters['employee_id'] ?? null, fn (Builder $query, int $employeeId) => $query->where('employee_id', $employeeId))
            ->when($filters['attendance_date'] ?? null, fn (Builder $query, string $attendanceDate) => $query->whereDate('attendance_date', $attendanceDate))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $dateFrom) => $query->whereDate('attendance_date', '>=', $dateFrom))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $dateTo) => $query->whereDate('attendance_date', '<=', $dateTo))
            ->when($filters['month'] ?? null, fn (Builder $query, int $month) => $query->whereMonth('attendance_date', $month))
            ->when($filters['year'] ?? null, fn (Builder $query, int $year) => $query->whereYear('attendance_date', $year))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['corrected_only'] ?? false, fn (Builder $query) => $query->whereNotNull('corrected_at'))
            ->orderByDesc('attendance_date')
            ->orderByDesc('id');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{start: CarbonInterface, end: CarbonInterface}
     */
    protected function summaryPeriod(array $filters): array
    {
        if (($filters['date_from'] ?? null) && ($filters['date_to'] ?? null)) {
            return [
                'start' => Carbon::parse((string) $filters['date_from'])->startOfDay(),
                'end' => Carbon::parse((string) $filters['date_to'])->startOfDay(),
            ];
        }

        $year = (int) ($filters['year'] ?? now()->year);
        $month = (int) ($filters['month'] ?? now()->month);
        $start = Carbon::create($year, $month, 1)->startOfDay();

        return [
            'start' => $start,
            'end' => $start->copy()->endOfMonth()->startOfDay(),
        ];
    }
}
