<?php

namespace App\Http\Resources;

use App\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LeaveRequest
 */
class LeaveResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'leave_type' => $this->leave_type,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'duration_type' => $this->duration_type,
            'total_days' => (string) $this->total_days,
            'reason' => $this->reason,
            'status' => $this->status,
            'hr_approval_status' => $this->hr_approval_status,
            'ceo_approval_status' => $this->ceo_approval_status,
            'rejection_reason' => $this->rejection_reason,
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'employee' => $this->whenLoaded('employee', fn () => $this->employee
                ? [
                    'id' => $this->employee->id,
                    'employee_id' => $this->employee->employee_id,
                    'full_name' => $this->employee->full_name,
                ]
                : null),
            'hr_approved_by_user' => $this->whenLoaded('hrApprovedBy', fn () => $this->hrApprovedBy
                ? [
                    'id' => $this->hrApprovedBy->id,
                    'name' => $this->hrApprovedBy->name,
                ]
                : null),
            'ceo_approved_by_user' => $this->whenLoaded('ceoApprovedBy', fn () => $this->ceoApprovedBy
                ? [
                    'id' => $this->ceoApprovedBy->id,
                    'name' => $this->ceoApprovedBy->name,
                ]
                : null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
