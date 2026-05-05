<?php

namespace App\Http\Resources\Dashboard;

use App\Http\Resources\LeaveResource;
use App\Http\Resources\PayrollBatchResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class HrDashboardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Collection<int, mixed> $pendingLeaveItems */
        $pendingLeaveItems = $this['pending_leave_requests']['items'];

        return [
            'date' => $this['date'],
            'today_attendance_summary' => $this['today_attendance_summary'],
            'pending_leave_requests' => [
                'total' => $this['pending_leave_requests']['total'],
                'items' => LeaveResource::collection($pendingLeaveItems)->resolve($request),
            ],
            'payroll_status' => [
                'counts' => $this['payroll_status']['counts'],
                'latest_batch' => $this['payroll_status']['latest_batch']
                    ? PayrollBatchResource::make($this['payroll_status']['latest_batch'])->resolve($request)
                    : null,
                'latest_pending_approval_batch' => $this['payroll_status']['latest_pending_approval_batch']
                    ? PayrollBatchResource::make($this['payroll_status']['latest_pending_approval_batch'])->resolve($request)
                    : null,
            ],
        ];
    }
}
