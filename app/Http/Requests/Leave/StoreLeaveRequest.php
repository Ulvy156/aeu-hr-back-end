<?php

namespace App\Http\Requests\Leave;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreLeaveRequest extends FormRequest
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
            'leave_type' => ['required', Rule::in(['annual', 'sick', 'maternity', 'unpaid', 'special'])],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'duration_type' => ['required', Rule::in(['full_day', 'half_day'])],
            'reason' => ['required', 'string'],
            'employee_id' => ['prohibited'],
            'total_days' => ['prohibited'],
            'status' => ['prohibited'],
            'hr_approval_status' => ['prohibited'],
            'ceo_approval_status' => ['prohibited'],
            'hr_approved_by' => ['prohibited'],
            'ceo_approved_by' => ['prohibited'],
            'rejection_reason' => ['prohibited'],
            'cancelled_at' => ['prohibited'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (
                    $this->input('duration_type') === 'half_day'
                    && $this->filled('start_date')
                    && $this->filled('end_date')
                    && $this->string('start_date')->value() !== $this->string('end_date')->value()
                ) {
                    $validator->errors()->add('duration_type', 'Half-day leave must start and end on the same date.');
                }
            },
        ];
    }
}
