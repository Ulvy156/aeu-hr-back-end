<?php

namespace App\Services\Dashboard;

use App\Models\LeaveRequest;
use App\Models\PayrollBatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class CeoDashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $today = CarbonImmutable::today();
        $pendingLeaveItems = $this->pendingLeaveApprovals();
        $recentPendingPayrollBatches = $this->recentPendingPayrollBatches();

        return [
            'date' => $today->toDateString(),
            'pending_leave_approvals' => [
                'total' => $this->pendingLeaveApprovalsCount(),
                'items' => $pendingLeaveItems,
            ],
            'payroll_approval_summary' => [
                'pending_approval_count' => $this->pendingPayrollApprovalsCount(),
                'latest_pending_approval_batch' => $recentPendingPayrollBatches->first(),
                'recent_pending_batches' => $recentPendingPayrollBatches,
            ],
        ];
    }

    /**
     * @return Collection<int, LeaveRequest>
     */
    protected function pendingLeaveApprovals(): Collection
    {
        return LeaveRequest::query()
            ->with([
                'employee:id,user_id,employee_id,full_name',
                'hrApprovedBy:id,name',
                'ceoApprovedBy:id,name',
            ])
            ->where('status', 'pending')
            ->where('ceo_approval_status', 'pending')
            ->orderBy('start_date')
            ->orderBy('id')
            ->limit(5)
            ->get();
    }

    protected function pendingLeaveApprovalsCount(): int
    {
        return (int) LeaveRequest::query()
            ->where('status', 'pending')
            ->where('ceo_approval_status', 'pending')
            ->count();
    }

    protected function pendingPayrollApprovalsCount(): int
    {
        return (int) PayrollBatch::query()
            ->where('status', 'pending_approval')
            ->count();
    }

    /**
     * @return Collection<int, PayrollBatch>
     */
    protected function recentPendingPayrollBatches(): Collection
    {
        return PayrollBatch::query()
            ->withCount('items')
            ->withSum('items as total_gross_salary', 'gross_salary')
            ->withSum('items as total_unpaid_deduction', 'unpaid_deduction')
            ->withSum('items as total_absence_deduction', 'absence_deduction')
            ->withSum('items as total_tax_amount', 'tax_amount')
            ->withSum('items as total_nssf_deduction', 'nssf_deduction')
            ->withSum('items as total_net_salary', 'net_salary')
            ->with([
                'generatedBy:id,name,email',
                'submittedBy:id,name,email',
            ])
            ->where('status', 'pending_approval')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();
    }
}
