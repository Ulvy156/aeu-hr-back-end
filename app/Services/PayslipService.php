<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\User;
use App\Support\FileStorage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class PayslipService
{
    public function __construct(
        protected CompanySettingService $companySettingService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, User $viewer): LengthAwarePaginator
    {
        $filters['employee_id'] = Employee::resolveId($filters['employee_id'] ?? null);
        $perPage = (int) ($filters['per_page'] ?? 15);

        $query = PayrollItem::query()
            ->select('payroll_items.*')
            ->join('payroll_batches', 'payroll_batches.id', '=', 'payroll_items.payroll_batch_id')
            ->with([
                'employee:id,user_id,employee_id,full_name',
                'payrollBatch:id,month,year,status,generated_at,submitted_at,approved_at,rejected_at',
            ])
            ->when($filters['employee_id'] ?? null, fn (Builder $query, int $employeeId) => $query->where('payroll_items.employee_id', $employeeId))
            ->when($filters['payroll_batch_id'] ?? null, fn (Builder $query, int $payrollBatchId) => $query->where('payroll_items.payroll_batch_id', $payrollBatchId))
            ->when($filters['month'] ?? null, fn (Builder $query, int $month) => $query->where('payroll_batches.month', $month))
            ->when($filters['year'] ?? null, fn (Builder $query, int $year) => $query->where('payroll_batches.year', $year))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('payroll_batches.status', $status))
            ->orderByDesc('payroll_batches.year')
            ->orderByDesc('payroll_batches.month')
            ->orderByDesc('payroll_items.id');

        if ($viewer->hasPermissionTo('payslips.view_any')) {
            return $query->paginate($perPage);
        }

        if (! $viewer->hasPermissionTo('payslips.view_own')) {
            throw ApiException::forbidden();
        }

        $employee = $viewer->loadMissing('employee')->employee;

        if (! $employee) {
            throw ApiException::forbidden('No employee profile is linked to this user account.');
        }

        return $query
            ->where('payroll_batches.status', 'approved')
            ->where('payroll_items.employee_id', $employee->id)
            ->paginate($perPage);
    }

    public function loadPayslip(PayrollItem $payrollItem): PayrollItem
    {
        return $payrollItem->load([
            'employee:id,user_id,employee_id,full_name',
            'payrollBatch:id,month,year,status,generated_at,submitted_at,approved_at,rejected_at',
        ]);
    }

    /**
     * @return BinaryFileResponse|Response
     */
    public function download(PayrollItem $payrollItem)
    {
        $payrollItem = $this->loadPayslip($payrollItem);
        $companySetting = $this->companySettingService->current();
        $fileName = $this->fileName($payrollItem);
        $pdf = Pdf::loadView('payslips.pdf', [
            'companySetting' => $companySetting,
            'payslip' => $payrollItem,
            'companyLogoDataUri' => $this->companyLogoDataUri($companySetting->company_logo),
        ])->setPaper('a4');

        return $pdf->download($fileName);
    }

    protected function fileName(PayrollItem $payrollItem): string
    {
        $employeeCode = $payrollItem->employee?->employee_id ?? 'employee';
        $month = str_pad((string) ($payrollItem->payrollBatch?->month ?? 0), 2, '0', STR_PAD_LEFT);
        $year = (string) ($payrollItem->payrollBatch?->year ?? now()->year);

        return "payslip-{$employeeCode}-{$year}-{$month}.pdf";
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
}
