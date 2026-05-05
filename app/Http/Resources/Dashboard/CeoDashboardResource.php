<?php

namespace App\Http\Resources\Dashboard;

use App\Http\Resources\LeaveResource;
use App\Http\Resources\PayrollBatchResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class CeoDashboardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Collection<int, mixed> $pendingLeaveItems */
        $pendingLeaveItems = $this['pending_leave_approvals']['items'];
        /** @var Collection<int, mixed> $recentPendingPayrollBatches */
        $recentPendingPayrollBatches = $this['payroll_approval_summary']['recent_pending_batches'];

        return [
            'date' => $this['date'],
            'pending_leave_approvals' => [
                'total' => $this['pending_leave_approvals']['total'],
                'items' => LeaveResource::collection($pendingLeaveItems)->resolve($request),
            ],
            'payroll_approval_summary' => [
                'pending_approval_count' => $this['payroll_approval_summary']['pending_approval_count'],
                'latest_pending_approval_batch' => $this['payroll_approval_summary']['latest_pending_approval_batch']
                    ? PayrollBatchResource::make($this['payroll_approval_summary']['latest_pending_approval_batch'])->resolve($request)
                    : null,
                'recent_pending_batches' => PayrollBatchResource::collection($recentPendingPayrollBatches)->resolve($request),
            ],
        ];
    }
}
