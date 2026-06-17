<?php

namespace App\Http\Requests\Employee;

use App\Enums\EmploymentStatus;
use App\Models\Position;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreEmployeeRequest extends FormRequest
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
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->whereNull('deleted_at')),
                'unique:employees,user_id',
            ],
            'employee_id' => ['prohibited'],
            'full_name' => ['required', 'string', 'max:255'],
            'password' => ['prohibited'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'date_of_birth' => ['nullable', 'date'],
            'phone_number' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'manager_id' => [
                'nullable',
                'integer',
                Rule::exists('employees', 'id')->where(fn ($query) => $query->whereNull('deleted_at')),
                Rule::requiredIf(fn () => ! $this->isCreatingCeo()),
            ],
            'join_date' => ['required', 'date'],
            'last_working_date' => ['nullable', 'date', 'after_or_equal:join_date'],
            'base_salary' => ['required', 'numeric', 'min:0'],
            'employment_status' => ['required', Rule::enum(EmploymentStatus::class)],
            'probation_end_date' => ['nullable', 'date', 'after_or_equal:join_date'],
            'emergency_contact' => ['nullable', 'string'],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'documents' => ['nullable', 'array', 'max:5'],
            'documents.*' => ['file', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx', 'max:20480'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.unique' => 'The selected user already has an employee profile.',
            'manager_id.required' => 'A manager is required for this employee.',
        ];
    }

    /**
     * Determine whether the user account being linked to this employee
     * holds the `ceo` role, which sits at the top of the reporting
     * hierarchy and therefore does not require a manager.
     */
    protected function isCreatingCeo(): bool
    {
        if (! $this->filled('user_id')) {
            return false;
        }

        $user = User::query()->find($this->input('user_id'));

        return (bool) $user?->hasRole('ceo');
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $files = $this->file('documents', []);
                if (! empty($files)) {
                    $totalSize = array_sum(array_map(fn ($f) => $f->getSize(), $files));
                    if ($totalSize > 20 * 1024 * 1024) {
                        $validator->errors()->add('documents', 'The total size of all documents must not exceed 20MB.');
                    }
                }
            },
            function (Validator $validator): void {
                $employmentStatus = $this->input('employment_status');
                $lastWorkingDate = $this->input('last_working_date');

                if (in_array($employmentStatus, [EmploymentStatus::FullTime->value, EmploymentStatus::Probation->value], true) && $lastWorkingDate) {
                    $validator->errors()->add('last_working_date', 'Active employees must not have a last working date.');
                }

                if (in_array($employmentStatus, [EmploymentStatus::Resigned->value, EmploymentStatus::Terminated->value], true) && ! $lastWorkingDate) {
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
