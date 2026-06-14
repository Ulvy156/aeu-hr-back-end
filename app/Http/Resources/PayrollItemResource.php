<?php

namespace App\Http\Resources;

use App\Models\PayrollItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PayrollItem
 */
class PayrollItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'base_salary' => (string) $this->base_salary,
            'daily_rate' => (string) $this->daily_rate,
            'working_days' => (string) $this->working_days,
            'present_days' => (string) $this->present_days,
            'absent_days' => (string) $this->absent_days,
            'unpaid_leave_days' => (string) $this->unpaid_leave_days,
            'maternity_leave_days' => (string) $this->maternity_leave_days,
            'gross_salary' => (string) $this->gross_salary,
            'unpaid_deduction' => (string) $this->unpaid_deduction,
            'absence_deduction' => (string) $this->absence_deduction,
            'maternity_deduction' => (string) $this->maternity_deduction,
            'taxable_salary' => (string) $this->taxable_salary,
            'tax_rate' => (string) $this->tax_rate,
            'tax_amount' => (string) $this->tax_amount,
            'nssf_deduction' => (string) $this->nssf_deduction,
            'tax_breakdown' => collect($this->tax_breakdown ?? [])
                ->map(fn (array $row): array => [
                    'bracket' => (string) ($row['bracket'] ?? ''),
                    'from' => (string) ($row['from'] ?? '0.00'),
                    'to' => $row['to'] === null ? null : (string) $row['to'],
                    'rate' => (string) ($row['rate'] ?? '0.0000'),
                    'taxable_amount' => (string) ($row['taxable_amount'] ?? '0.00'),
                    'tax_amount' => (string) ($row['tax_amount'] ?? '0.00'),
                ])
                ->values()
                ->all(),
            'net_salary' => (string) $this->net_salary,
            'status' => $this->status,
            'employee' => $this->whenLoaded('employee', fn () => $this->employee
                ? [
                    'id' => $this->employee->id,
                    'employee_id' => $this->employee->employee_id,
                    'full_name' => $this->employee->full_name,
                ]
                : null),
            'payroll_batch' => $this->whenLoaded('payrollBatch', fn () => $this->payrollBatch
                ? [
                    'id' => $this->payrollBatch->id,
                    'month' => $this->payrollBatch->month,
                    'year' => $this->payrollBatch->year,
                    'status' => $this->payrollBatch->status,
                ]
                : null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
