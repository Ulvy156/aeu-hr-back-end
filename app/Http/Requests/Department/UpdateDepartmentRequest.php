<?php

namespace App\Http\Requests\Department;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepartmentRequest extends FormRequest
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
        $department = $this->route('department');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('departments', 'name')->ignore($department?->id)],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }
}
