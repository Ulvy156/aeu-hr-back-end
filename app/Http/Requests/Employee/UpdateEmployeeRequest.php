<?php

namespace App\Http\Requests\Employee;

use App\Models\Position;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateEmployeeRequest extends FormRequest
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
        $employee = $this->route('employee');

        return [
            'employee_id' => ['required', 'string', 'max:50', Rule::unique('employees', 'employee_id')->ignore($employee?->id)],
            'full_name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($employee?->user_id),
                Rule::unique('employees', 'email')->ignore($employee?->id)->where(fn ($query) => $query->whereNull('deleted_at')),
            ],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'date_of_birth' => ['nullable', 'date'],
            'phone_number' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'join_date' => ['required', 'date'],
            'last_working_date' => ['nullable', 'date', 'after_or_equal:join_date'],
            'base_salary' => ['required', 'numeric', 'min:0'],
            'employment_status' => ['required', Rule::in(['active', 'resigned', 'terminated'])],
            'emergency_contact' => ['nullable', 'string'],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $employmentStatus = $this->input('employment_status');
                $lastWorkingDate = $this->input('last_working_date');

                if ($employmentStatus === 'active' && $lastWorkingDate) {
                    $validator->errors()->add('last_working_date', 'Active employees must not have a last working date.');
                }

                if (in_array($employmentStatus, ['resigned', 'terminated'], true) && ! $lastWorkingDate) {
                    $validator->errors()->add('last_working_date', 'Resigned or terminated employees must have a last working date.');
                }

                if (! $this->filled('position_id')) {
                    return;
                }

                $position = Position::query()->find($this->integer('position_id'));

                if (! $position) {
                    return;
                }

                if ($position->department_id && $this->filled('department_id') && $position->department_id !== $this->integer('department_id')) {
                    $validator->errors()->add('position_id', 'The selected position does not belong to the selected department.');
                }
            },
        ];
    }
}
