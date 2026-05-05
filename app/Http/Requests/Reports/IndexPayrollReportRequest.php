<?php

namespace App\Http\Requests\Reports;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPayrollReportRequest extends FormRequest
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
            'report_type' => ['nullable', Rule::in(['employee_list', 'monthly_summary', 'status_summary'])],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'status' => ['nullable', Rule::in(['draft', 'pending_approval', 'approved', 'rejected'])],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
