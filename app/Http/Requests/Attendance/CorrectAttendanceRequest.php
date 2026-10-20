<?php

namespace App\Http\Requests\Attendance;

use App\Models\Attendance;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CorrectAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'clock_in_time' => ['sometimes', 'nullable', 'date'],
            'clock_out_time' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', 'required', Rule::in(['present', 'late', 'absent', 'missing_clock_out', 'missing_clock_in', 'missing_both'])],
            'correction_reason' => ['required', 'string'],
            'clock_in_latitude' => ['prohibited'],
            'clock_in_longitude' => ['prohibited'],
            'clock_out_latitude' => ['prohibited'],
            'clock_out_longitude' => ['prohibited'],
            'is_late' => ['prohibited'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasAny(['clock_in_time', 'clock_out_time', 'status'])) {
                    $validator->errors()->add('attendance', 'At least one attendance field must be corrected.');
                }

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $attendance = $this->route('attendance');
                $clockIn = $this->exists('clock_in_time') ? $this->input('clock_in_time') : ($attendance instanceof Attendance ? $attendance->clock_in_time : null);
                $clockOut = $this->exists('clock_out_time') ? $this->input('clock_out_time') : ($attendance instanceof Attendance ? $attendance->clock_out_time : null);
                if ($clockIn && $clockOut && strtotime((string) $clockOut) < strtotime((string) $clockIn)) {
                    $validator->errors()->add('clock_out_time', 'The clock out time must be after or equal to the clock in time.');
                }
            },
        ];
    }
}
