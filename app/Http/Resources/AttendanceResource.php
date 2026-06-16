<?php

namespace App\Http\Resources;

use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Attendance
 */
class AttendanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attendance_date' => $this->attendance_date?->toDateString(),
            'clock_in_time' => $this->clock_in_time?->toISOString(),
            'clock_out_time' => $this->clock_out_time?->toISOString(),
            'status' => $this->status,
            'is_late' => $this->is_late,
            'correction_reason' => $this->correction_reason,
            'corrected_at' => $this->corrected_at?->toISOString(),
            'employee' => $this->whenLoaded('employee', fn () => $this->employee
                ? [
                    'id' => $this->employee->id,
                    'employee_id' => $this->employee->employee_id,
                    'full_name' => $this->employee->full_name,
                ]
                : null),
            'corrected_by_user' => $this->whenLoaded('correctedBy', fn () => $this->correctedBy
                ? [
                    'id' => $this->correctedBy->id,
                    'name' => $this->correctedBy->name,
                    'email' => $this->correctedBy->email,
                ]
                : null),
            'proxied_clock_in_by_user' => $this->whenLoaded('proxiedClockInBy', fn () => $this->proxiedClockInBy
                ? [
                    'id' => $this->proxiedClockInBy->id,
                    'name' => $this->proxiedClockInBy->name,
                    'email' => $this->proxiedClockInBy->email,
                ]
                : null),
            'proxied_clock_out_by_user' => $this->whenLoaded('proxiedClockOutBy', fn () => $this->proxiedClockOutBy
                ? [
                    'id' => $this->proxiedClockOutBy->id,
                    'name' => $this->proxiedClockOutBy->name,
                    'email' => $this->proxiedClockOutBy->email,
                ]
                : null),
            'qr_clock_in' => $this->qr_clock_in,
            'qr_clock_out' => $this->qr_clock_out,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
