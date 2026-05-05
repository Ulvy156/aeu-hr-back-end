<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'payroll_batch_id',
    'employee_id',
    'base_salary',
    'daily_rate',
    'working_days',
    'present_days',
    'absent_days',
    'unpaid_leave_days',
    'gross_salary',
    'unpaid_deduction',
    'absence_deduction',
    'taxable_salary',
    'tax_rate',
    'tax_amount',
    'nssf_deduction',
    'tax_breakdown',
    'net_salary',
    'status',
])]
class PayrollItem extends Model
{
    use HasFactory;

    public function payrollBatch(): BelongsTo
    {
        return $this->belongsTo(PayrollBatch::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_salary' => 'decimal:2',
            'daily_rate' => 'decimal:2',
            'working_days' => 'decimal:2',
            'present_days' => 'decimal:2',
            'absent_days' => 'decimal:2',
            'unpaid_leave_days' => 'decimal:2',
            'gross_salary' => 'decimal:2',
            'unpaid_deduction' => 'decimal:2',
            'absence_deduction' => 'decimal:2',
            'taxable_salary' => 'decimal:2',
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:2',
            'nssf_deduction' => 'decimal:2',
            'tax_breakdown' => 'array',
            'net_salary' => 'decimal:2',
        ];
    }
}
