<?php

namespace App\Http\Resources\Dashboard;

use App\Http\Resources\AttendanceResource;
use App\Http\Resources\LeaveBalanceResource;
use App\Http\Resources\PayslipResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeDashboardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'today_attendance' => $this['today_attendance']
                ? AttendanceResource::make($this['today_attendance'])->resolve($request)
                : null,
            'leave_balance' => [
                'employee' => $this['leave_balance']['employee'],
                'year' => $this['leave_balance']['year'],
                'balances' => LeaveBalanceResource::collection(collect($this['leave_balance']['balances']))->resolve($request),
            ],
            'latest_approved_payslip' => $this['latest_approved_payslip']
                ? PayslipResource::make($this['latest_approved_payslip'])->resolve($request)
                : null,
        ];
    }
}
