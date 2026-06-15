<?php

namespace App\Http\Requests\EmployeeUpgradeRequest;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RejectEmployeeUpgradeRequestRequest extends FormRequest
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
        ];
    }
}
