<?php

namespace App\Http\Requests\Leave;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RejectLeaveRequest extends FormRequest
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
            'rejection_reason' => ['required', 'string'],
            'status' => ['prohibited'],
            'hr_approval_status' => ['prohibited'],
            'ceo_approval_status' => ['prohibited'],
        ];
    }
}
