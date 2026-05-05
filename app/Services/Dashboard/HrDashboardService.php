<?php

namespace App\Services\Dashboard;

use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\PayrollBatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class HrDashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $today = CarbonImmutable::today();
        $pendingLeaveItems = $this->pendingLeaveRequests();

        return [
            'date' => $today->toDateString(),
            'today_attendance_summary' => $this->todayAttendanceSummary($today),
            'pending_leave_requests' => [
                'total' => $this->pendingLeaveRequestsCount(),
                'items' => $pendingLeaveItems,
            ],
            'payroll_status' => [
                'counts' => $this->payrollStatusCounts(),
                'latest_batch' => $this->latestPayrollBatch(),
                'latest_pending_approval_batch' => $this->latestPendingApprovalBatch(),
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    protected function todayAttendanceSummary(CarbonImmutable $today): array
    {
        $summary = Attendance::query()
            ->whereDate('attendance_date', $today->toDateString())
            ->selectRaw('COUNT(*) as total_records')
            ->selectRaw("SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count")
            ->selectRaw("SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count")
            ->selectRaw("SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count")
            ->selectRaw("SUM(CASE WHEN status = 'missing_clock_out' THEN 1 ELSE 0 END) as missing_clock_out_count")
            ->first();

        return [
            'total_records' => (int) ($summary?->total_records ?? 0),
            'present_count' => (int) ($summary?->present_count ?? 0),
            'late_count' => (int) ($summary?->late_count ?? 0),
            'absent_count' => (int) ($summary?->absent_count ?? 0),
            'missing_clock_out_count' => (int) ($summary?->missing_clock_out_count ?? 0),
        ];
    }

    /**
     * @return Collection<int, LeaveRequest>
     */
    protected function pendingLeaveRequests(): Collection
    {
        return LeaveRequest::query()
            ->with([
                'employee:id,user_id,employee_id,full_name',
                'hrApprovedBy:id,name',
                'ceoApprovedBy:id,name',
            ])
            ->where('status', 'pending')
            ->where('hr_approval_status', 'pending')
            ->orderBy('start_date')
            ->orderBy('id')
            ->limit(5)
            ->get();
    }

    protected function pendingLeaveRequestsCount(): int
    {
        return (int) LeaveRequest::query()
            ->where('status', 'pending')
            ->where('hr_approval_status', 'pending')
            ->count();
    }

    /**
     * @return array<string, int>
     */
    protected function payrollStatusCounts(): array
    {
        $summary = PayrollBatch::query()
            ->selectRaw("SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_count")
            ->selectRaw("SUM(CASE WHEN status = 'pending_approval' THEN 1 ELSE 0 END) as pending_approval_count")
            ->selectRaw("SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_count")
            ->selectRaw("SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count")
            ->first();

        return [
            'draft' => (int) ($summary?->draft_count ?? 0),
            'pending_approval' => (int) ($summary?->pending_approval_count ?? 0),
            'approved' => (int) ($summary?->approved_count ?? 0),
            'rejected' => (int) ($summary?->rejected_count ?? 0),
        ];
    }

    protected function latestPayrollBatch(): ?PayrollBatch
    {
        return $this->payrollSummaryQuery()
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderByDesc('id')
            ->first();
    }

    protected function latestPendingApprovalBatch(): ?PayrollBatch
    {
        return $this->payrollSummaryQuery()
            ->where('status', 'pending_approval')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->first();
    }

    protected function payrollSummaryQuery()
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
                'approvedBy:id,name,email',
                'rejectedBy:id,name,email',
            ]);
    }
}
