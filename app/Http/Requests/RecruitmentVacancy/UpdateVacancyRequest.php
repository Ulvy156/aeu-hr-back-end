<?php

namespace App\Http\Requests\RecruitmentVacancy;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVacancyRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            'description' => ['required', 'string'],
            'required_headcount' => ['required', 'integer', 'min:1'],
            'target_hiring_date' => ['required', 'date'],
            'status' => ['prohibited'],
            'filled_headcount' => ['prohibited'],
            'created_by' => ['prohibited'],
        ];
    }
}
