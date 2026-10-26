<?php

namespace App\Http\Requests\RecruitmentVacancy;

use App\Rules\RequiredRichText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVacancyRequest extends FormRequest
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
            'description' => ['required', 'string', new RequiredRichText],
            'required_headcount' => ['required', 'integer', 'min:1'],
            'open_date' => ['required', 'date'],
            'close_date' => ['nullable', 'date', 'after:open_date'],
            'target_hiring_date' => ['required', 'date', 'after_or_equal:open_date'],
            'status' => ['prohibited'],
            'filled_headcount' => ['prohibited'],
            'created_by' => ['prohibited'],
        ];
    }
}
