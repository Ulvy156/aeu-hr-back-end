<?php

namespace App\Services\Dashboard;

use App\Exceptions\ApiException;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\User;
use App\Services\LeaveService;
use Carbon\CarbonImmutable;

class EmployeeDashboardService
{
    public function __construct(
        protected LeaveService $leaveService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(User $user): array
    {
        $employee = $this->employeeForUserOrFail($user);
        $today = CarbonImmutable::today();

        return [
            'today_attendance' => Attendance::query()
                ->with('employee:id,user_id,employee_id,full_name')
                ->whereBelongsTo($employee)
                ->whereDate('attendance_date', $today->toDateString())
                ->latest('id')
                ->first(),
            'leave_balance' => $this->leaveService->balances(
                viewer: $user,
                filters: ['year' => $today->year],
            ),
            'latest_approved_payslip' => PayrollItem::query()
                ->with([
                    'employee:id,user_id,employee_id,full_name',
                    'payrollBatch:id,month,year,status,generated_at,submitted_at,approved_at,rejected_at',
                ])
                ->whereBelongsTo($employee)
                ->whereHas('payrollBatch', fn ($query) => $query->where('status', 'approved'))
                ->where('payroll_items.status', 'locked')
                ->join('payroll_batches', 'payroll_batches.id', '=', 'payroll_items.payroll_batch_id')
                ->orderByDesc('payroll_batches.approved_at')
                ->orderByDesc('payroll_items.id')
                ->select('payroll_items.*')
                ->first(),
        ];
    }

    protected function employeeForUserOrFail(User $user): Employee
    {
        $employee = $user->loadMissing('employee')->employee;

        if (! $employee) {
            throw ApiException::forbidden('No employee profile is linked to this user account.');
        }

        return $employee;
    }
}
