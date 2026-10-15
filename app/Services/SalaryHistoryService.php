<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\EmploymentHistory;
use App\Models\PayrollItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class SalaryHistoryService
{
    /** Employee must be locked by the caller, also serializing payroll generation. */
    public function assertPayrollAllowsChange(Employee $employee, CarbonInterface $effectiveMonth): void
    {
        if (EmploymentHistory::query()->where('employee_id', $employee->id)->where('field', EmploymentHistory::FIELD_BASE_SALARY)
            ->where('id', '>', $employee->salary_history_baseline_history_id ?? 0)
            ->whereDate('effective_date', '>', $effectiveMonth->toDateString())->exists()) {
            throw ApiException::unprocessable('A newer salary change already exists. Cancel the older promotion and submit a replacement for next month.');
        }
        $periods = PayrollItem::query()->where('employee_id', $employee->id)
            ->whereHas('payrollBatch', function ($query) use ($effectiveMonth) {
                $query->where('year', '>', $effectiveMonth->year)
                    ->orWhere(fn ($q) => $q->where('year', $effectiveMonth->year)->where('month', '>=', $effectiveMonth->month));
            })->with('payrollBatch:id,month,year')->get()
            ->map(fn ($item) => sprintf('%04d-%02d', $item->payrollBatch->year, $item->payrollBatch->month))
            ->unique()->sort()->values();

        if ($periods->isNotEmpty()) {
            throw ApiException::unprocessable('Salary change blocked by existing payroll: '.$periods->implode(', ').'. Delete an editable draft first; approved payroll stays locked.');
        }
    }

    /** @return Collection<int, Collection<int, EmploymentHistory>> */
    public function historiesFor(array $employeeIds): Collection
    {
        return EmploymentHistory::query()->whereIn('employee_id', $employeeIds)
            ->where('field', EmploymentHistory::FIELD_BASE_SALARY)
            ->orderBy('effective_date')->orderBy('id')->get()->groupBy('employee_id');
    }

    public function resolve(Employee $employee, CarbonInterface $month, Collection $histories): float
    {
        $coverage = $employee->salary_history_start_month;
        if (! $coverage || $month->copy()->startOfMonth()->lt($coverage)) {
            throw ApiException::unprocessable("Salary history is not verified for {$employee->employee_id} in {$month->format('Y-m')}. Reconcile historical salary coverage before generating payroll.");
        }

        // Legacy changes may omit direct edits or have future dates despite immediate application.
        // The rollout baseline supersedes those records until history is explicitly reconciled.
        $histories = $histories->filter(fn ($history) => $history->id > ($employee->salary_history_baseline_history_id ?? 0));

        if ($histories->contains(fn ($history) => $history->effective_date->isSameMonth($month) && $history->effective_date->day !== 1)) {
            throw ApiException::unprocessable("Reconcile the legacy mid-month salary change for {$employee->employee_id} in {$month->format('Y-m')} before generating payroll.");
        }

        $latest = $histories->last(fn ($history) => $history->effective_date->lte($month));
        $next = $histories->first(fn ($history) => $history->effective_date->gt($month));
        $value = $latest ? ($latest->new_value['value'] ?? null)
            : ($next ? ($next->old_value['value'] ?? null) : $employee->salary_history_baseline);

        if (! is_numeric($value)) {
            throw ApiException::unprocessable("Missing verified salary for {$employee->employee_id} in {$month->format('Y-m')}.");
        }

        return (float) $value;
    }
}
