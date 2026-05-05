<?php

namespace App\Services\Reports;

use App\Exports\ArrayReportExport;
use App\Models\PayrollBatch;
use App\Models\PayrollItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Arr;

class PayrollReportService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function report(array $filters): array
    {
        $reportType = (string) ($filters['report_type'] ?? 'employee_list');

        return match ($reportType) {
            'monthly_summary' => $this->monthlySummaryReport($filters),
            'status_summary' => $this->statusSummaryReport($filters),
            default => $this->employeeListReport($filters),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{file_name: string, export: ArrayReportExport}
     */
    public function export(array $filters): array
    {
        $reportType = (string) ($filters['report_type'] ?? 'employee_list');

        return match ($reportType) {
            'monthly_summary' => $this->exportMonthlySummary($filters),
            'status_summary' => $this->exportStatusSummary($filters),
            default => $this->exportEmployeeList($filters),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function employeeListReport(array $filters): array
    {
        $perPage = (int) ($filters['per_page'] ?? 15);
        $query = $this->employeeListQuery($filters);
        $summary = (clone $query)
            ->reorder()
            ->selectRaw('COUNT(payroll_items.id) as item_count')
            ->selectRaw('COALESCE(SUM(payroll_items.gross_salary), 0) as total_gross_salary')
            ->selectRaw('COALESCE(SUM(payroll_items.unpaid_deduction), 0) as total_unpaid_deduction')
            ->selectRaw('COALESCE(SUM(payroll_items.absence_deduction), 0) as total_absence_deduction')
            ->selectRaw('COALESCE(SUM(payroll_items.tax_amount), 0) as total_tax_amount')
            ->selectRaw('COALESCE(SUM(payroll_items.nssf_deduction), 0) as total_nssf_deduction')
            ->selectRaw('COALESCE(SUM(payroll_items.net_salary), 0) as total_net_salary')
            ->first();

        return [
            'report_type' => 'employee_list',
            'paginated' => true,
            'resource' => 'payroll_item',
            'paginator' => $query->paginate($perPage),
            'summary' => [
                'item_count' => (int) ($summary?->item_count ?? 0),
                'gross_salary' => $this->formatMoney((float) ($summary?->total_gross_salary ?? 0)),
                'unpaid_deduction' => $this->formatMoney((float) ($summary?->total_unpaid_deduction ?? 0)),
                'absence_deduction' => $this->formatMoney((float) ($summary?->total_absence_deduction ?? 0)),
                'tax_amount' => $this->formatMoney((float) ($summary?->total_tax_amount ?? 0)),
                'nssf_deduction' => $this->formatMoney((float) ($summary?->total_nssf_deduction ?? 0)),
                'net_salary' => $this->formatMoney((float) ($summary?->total_net_salary ?? 0)),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function monthlySummaryReport(array $filters): array
    {
        $perPage = (int) ($filters['per_page'] ?? 15);
        $query = $this->monthlySummaryQuery($filters);
        $summary = (clone $query)
            ->reorder()
            ->selectRaw('COUNT(payroll_batches.id) as batch_count')
            ->selectRaw("SUM(CASE WHEN payroll_batches.status = 'draft' THEN 1 ELSE 0 END) as draft_count")
            ->selectRaw("SUM(CASE WHEN payroll_batches.status = 'pending_approval' THEN 1 ELSE 0 END) as pending_approval_count")
            ->selectRaw("SUM(CASE WHEN payroll_batches.status = 'approved' THEN 1 ELSE 0 END) as approved_count")
            ->selectRaw("SUM(CASE WHEN payroll_batches.status = 'rejected' THEN 1 ELSE 0 END) as rejected_count")
            ->selectRaw('COALESCE(SUM((SELECT SUM(payroll_items.gross_salary) FROM payroll_items WHERE payroll_items.payroll_batch_id = payroll_batches.id)), 0) as total_gross_salary')
            ->selectRaw('COALESCE(SUM((SELECT SUM(payroll_items.nssf_deduction) FROM payroll_items WHERE payroll_items.payroll_batch_id = payroll_batches.id)), 0) as total_nssf_deduction')
            ->selectRaw('COALESCE(SUM((SELECT SUM(payroll_items.net_salary) FROM payroll_items WHERE payroll_items.payroll_batch_id = payroll_batches.id)), 0) as total_net_salary')
            ->first();

        return [
            'report_type' => 'monthly_summary',
            'paginated' => true,
            'resource' => 'payroll_batch',
            'paginator' => $query->paginate($perPage),
            'summary' => [
                'batch_count' => (int) ($summary?->batch_count ?? 0),
                'statuses' => [
                    'draft' => (int) ($summary?->draft_count ?? 0),
                    'pending_approval' => (int) ($summary?->pending_approval_count ?? 0),
                    'approved' => (int) ($summary?->approved_count ?? 0),
                    'rejected' => (int) ($summary?->rejected_count ?? 0),
                ],
                'gross_salary' => $this->formatMoney((float) ($summary?->total_gross_salary ?? 0)),
                'nssf_deduction' => $this->formatMoney((float) ($summary?->total_nssf_deduction ?? 0)),
                'net_salary' => $this->formatMoney((float) ($summary?->total_net_salary ?? 0)),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function statusSummaryReport(array $filters): array
    {
        $rows = $this->statusSummaryRows($filters);

        return [
            'report_type' => 'status_summary',
            'paginated' => false,
            'summary' => [
                'status_count' => $rows->count(),
                'batch_count' => (int) $rows->sum('batch_count'),
                'item_count' => (int) $rows->sum('item_count'),
                'gross_salary' => $this->formatMoney((float) $rows->sum('gross_salary_raw')),
                'nssf_deduction' => $this->formatMoney((float) $rows->sum('nssf_deduction_raw')),
                'net_salary' => $this->formatMoney((float) $rows->sum('net_salary_raw')),
            ],
            'items' => $rows->map(fn (array $row): array => Arr::except($row, ['gross_salary_raw', 'nssf_deduction_raw', 'net_salary_raw']))->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{file_name: string, export: ArrayReportExport}
     */
    protected function exportEmployeeList(array $filters): array
    {
        $rows = $this->employeeListQuery($filters)->get()->map(function (PayrollItem $item): array {
            return [
                $item->employee?->employee_id,
                $item->employee?->full_name,
                $item->payrollBatch?->month,
                $item->payrollBatch?->year,
                $item->payrollBatch?->status,
                (string) $item->gross_salary,
                (string) $item->tax_amount,
                (string) $item->nssf_deduction,
                (string) $item->net_salary,
                $item->status,
            ];
        })->all();

        return [
            'file_name' => 'payroll-employee-list-report.xlsx',
            'export' => new ArrayReportExport(
                headings: ['Employee ID', 'Employee Name', 'Month', 'Year', 'Batch Status', 'Gross Salary', 'Tax Amount', 'NSSF Deduction', 'Net Salary', 'Item Status'],
                rows: $rows,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{file_name: string, export: ArrayReportExport}
     */
    protected function exportMonthlySummary(array $filters): array
    {
        $rows = $this->monthlySummaryQuery($filters)->get()->map(function (PayrollBatch $batch): array {
            return [
                $batch->month,
                $batch->year,
                $batch->status,
                $batch->items_count,
                $batch->total_gross_salary ? $this->formatMoney((float) $batch->total_gross_salary) : '0.00',
                $batch->total_tax_amount ? $this->formatMoney((float) $batch->total_tax_amount) : '0.00',
                $batch->total_nssf_deduction ? $this->formatMoney((float) $batch->total_nssf_deduction) : '0.00',
                $batch->total_net_salary ? $this->formatMoney((float) $batch->total_net_salary) : '0.00',
                $batch->generated_at?->toISOString(),
                $batch->approved_at?->toISOString(),
            ];
        })->all();

        return [
            'file_name' => 'payroll-monthly-summary-report.xlsx',
            'export' => new ArrayReportExport(
                headings: ['Month', 'Year', 'Status', 'Item Count', 'Gross Salary', 'Tax Amount', 'NSSF Deduction', 'Net Salary', 'Generated At', 'Approved At'],
                rows: $rows,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{file_name: string, export: ArrayReportExport}
     */
    protected function exportStatusSummary(array $filters): array
    {
        $rows = $this->statusSummaryRows($filters)
            ->map(fn (array $row): array => [
                $row['status'],
                $row['batch_count'],
                $row['item_count'],
                $row['gross_salary'],
                $row['tax_amount'],
                $row['nssf_deduction'],
                $row['net_salary'],
            ])
            ->all();

        return [
            'file_name' => 'payroll-status-summary-report.xlsx',
            'export' => new ArrayReportExport(
                headings: ['Status', 'Batch Count', 'Item Count', 'Gross Salary', 'Tax Amount', 'NSSF Deduction', 'Net Salary'],
                rows: $rows,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function employeeListQuery(array $filters): Builder
    {
        return PayrollItem::query()
            ->select('payroll_items.*')
            ->join('payroll_batches', 'payroll_batches.id', '=', 'payroll_items.payroll_batch_id')
            ->with([
                'employee:id,user_id,employee_id,full_name',
                'payrollBatch:id,month,year,status,generated_at,submitted_at,approved_at,rejected_at',
            ])
            ->when($filters['employee_id'] ?? null, fn (Builder $query, int $employeeId) => $query->where('payroll_items.employee_id', $employeeId))
            ->when($filters['month'] ?? null, fn (Builder $query, int $month) => $query->where('payroll_batches.month', $month))
            ->when($filters['year'] ?? null, fn (Builder $query, int $year) => $query->where('payroll_batches.year', $year))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('payroll_batches.status', $status))
            ->orderByDesc('payroll_batches.year')
            ->orderByDesc('payroll_batches.month')
            ->orderBy('payroll_items.employee_id');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function monthlySummaryQuery(array $filters): Builder
    {
        return PayrollBatch::query()
            ->withCount('items')
            ->withSum('items as total_gross_salary', 'gross_salary')
            ->withSum('items as total_tax_amount', 'tax_amount')
            ->withSum('items as total_nssf_deduction', 'nssf_deduction')
            ->withSum('items as total_net_salary', 'net_salary')
            ->with([
                'generatedBy:id,name,email',
                'submittedBy:id,name,email',
                'approvedBy:id,name,email',
                'rejectedBy:id,name,email',
            ])
            ->when($filters['month'] ?? null, fn (Builder $query, int $month) => $query->where('month', $month))
            ->when($filters['year'] ?? null, fn (Builder $query, int $year) => $query->where('year', $year))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when(
                $filters['employee_id'] ?? null,
                fn (Builder $query, int $employeeId) => $query->whereHas('items', fn (Builder $itemQuery) => $itemQuery->where('employee_id', $employeeId)),
            )
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderByDesc('id');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    protected function statusSummaryRows(array $filters): Collection
    {
        return PayrollBatch::query()
            ->leftJoin('payroll_items', 'payroll_items.payroll_batch_id', '=', 'payroll_batches.id')
            ->when($filters['month'] ?? null, fn ($query, int $month) => $query->where('payroll_batches.month', $month))
            ->when($filters['year'] ?? null, fn ($query, int $year) => $query->where('payroll_batches.year', $year))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('payroll_batches.status', $status))
            ->when($filters['employee_id'] ?? null, fn ($query, int $employeeId) => $query->where('payroll_items.employee_id', $employeeId))
            ->groupBy('payroll_batches.status')
            ->orderBy('payroll_batches.status')
            ->get([
                'payroll_batches.status',
            ])
            ->map(function (PayrollBatch $batch) use ($filters): array {
                $aggregate = PayrollBatch::query()
                    ->leftJoin('payroll_items', 'payroll_items.payroll_batch_id', '=', 'payroll_batches.id')
                    ->where('payroll_batches.status', $batch->status)
                    ->when($filters['month'] ?? null, fn ($query, int $month) => $query->where('payroll_batches.month', $month))
                    ->when($filters['year'] ?? null, fn ($query, int $year) => $query->where('payroll_batches.year', $year))
                    ->when($filters['employee_id'] ?? null, fn ($query, int $employeeId) => $query->where('payroll_items.employee_id', $employeeId))
                    ->selectRaw('COUNT(DISTINCT payroll_batches.id) as batch_count')
                    ->selectRaw('COUNT(payroll_items.id) as item_count')
                    ->selectRaw('COALESCE(SUM(payroll_items.gross_salary), 0) as gross_salary')
                    ->selectRaw('COALESCE(SUM(payroll_items.tax_amount), 0) as tax_amount')
                    ->selectRaw('COALESCE(SUM(payroll_items.nssf_deduction), 0) as nssf_deduction')
                    ->selectRaw('COALESCE(SUM(payroll_items.net_salary), 0) as net_salary')
                    ->first();

                return [
                    'status' => $batch->status,
                    'batch_count' => (int) ($aggregate?->batch_count ?? 0),
                    'item_count' => (int) ($aggregate?->item_count ?? 0),
                    'gross_salary' => $this->formatMoney((float) ($aggregate?->gross_salary ?? 0)),
                    'tax_amount' => $this->formatMoney((float) ($aggregate?->tax_amount ?? 0)),
                    'nssf_deduction' => $this->formatMoney((float) ($aggregate?->nssf_deduction ?? 0)),
                    'net_salary' => $this->formatMoney((float) ($aggregate?->net_salary ?? 0)),
                    'gross_salary_raw' => (float) ($aggregate?->gross_salary ?? 0),
                    'nssf_deduction_raw' => (float) ($aggregate?->nssf_deduction ?? 0),
                    'net_salary_raw' => (float) ($aggregate?->net_salary ?? 0),
                ];
            });
    }

    protected function formatMoney(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
