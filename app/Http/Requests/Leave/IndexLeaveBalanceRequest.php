<?php

namespace App\Http\Requests\Leave;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class IndexLeaveBalanceRequest extends FormRequest
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
            'employee_id' => ['nullable', 'string', 'exists:employees,employee_id'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ];
    }
}
