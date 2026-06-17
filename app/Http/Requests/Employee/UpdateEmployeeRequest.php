<?php

namespace App\Http\Requests\Employee;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
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
        return [
            'employee_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'full_name' => ['required', 'string', 'max:255'],
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
                Rule::requiredIf(fn () => ! $this->isUpdatingCeo()),
            ],
            'join_date' => ['required', 'date'],
            'last_working_date' => ['nullable', 'date', 'after_or_equal:join_date'],
            'base_salary' => ['required', 'numeric', 'min:0'],
            'employment_status' => ['required', Rule::enum(EmploymentStatus::class)],
            'probation_end_date' => ['nullable', 'date', 'after_or_equal:join_date'],
            'emergency_contact' => ['nullable', 'string'],
            'profile_photo' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'documents' => ['nullable', 'array', 'max:5'],
            'documents.*' => ['file', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx', 'max:20480'],
            'remove_documents' => ['nullable', 'array'],
            'remove_documents.*' => ['integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'manager_id.required' => 'A manager is required for this employee.',
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $employee = $this->route('employee');
                if (! $employee instanceof Employee) {
                    return;
                }

                $removeIndices = array_unique(array_map('intval', $this->input('remove_documents', [])));
                $currentDocuments = $employee->documents ?? [];
                $currentCount = count($currentDocuments);

                foreach ($removeIndices as $index) {
                    if ($index >= $currentCount) {
                        $validator->errors()->add('remove_documents', "Document index [{$index}] does not exist.");
                    }
                }

                $remaining = $currentCount - count(array_filter($removeIndices, fn (int $i) => $i < $currentCount));
                $newUploads = count($this->file('documents', []));

                if ($remaining + $newUploads > 5) {
                    $validator->errors()->add('documents', 'An employee may have at most 5 documents.');
                }

                $remainingSize = 0;
                foreach ($currentDocuments as $i => $doc) {
                    if (! in_array($i, $removeIndices, true)) {
                        $remainingSize += $doc['size'] ?? 0;
                    }
                }

                $newFiles = $this->file('documents', []);
                $newSize = array_sum(array_map(fn ($f) => $f->getSize(), $newFiles));

                if ($remainingSize + $newSize > 20 * 1024 * 1024) {
                    $validator->errors()->add('documents', 'The total size of all documents must not exceed 20MB.');
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
            function (Validator $validator): void {
                if (! $this->filled('manager_id')) {
                    return;
                }

                $employee = $this->route('employee');

                if (! $employee instanceof Employee) {
                    return;
                }

                $managerId = $this->integer('manager_id');

                if ($managerId === $employee->id) {
                    $validator->errors()->add('manager_id', 'An employee cannot be their own manager.');

                    return;
                }

                $visited = [];
                $currentId = $managerId;

                for ($i = 0; $i < 50; $i++) {
                    if ($currentId === $employee->id) {
                        $validator->errors()->add('manager_id', 'This change would create a circular reporting relationship.');

                        break;
                    }

                    if (in_array($currentId, $visited, true)) {
                        break;
                    }

                    $visited[] = $currentId;

                    $managerOfCurrent = Employee::query()->whereKey($currentId)->value('manager_id');

                    if ($managerOfCurrent === null) {
                        break;
                    }

                    $currentId = (int) $managerOfCurrent;
                }
            },
        ];
    }

    /**
     * Determine whether the employee being updated holds the `ceo` role
     * (via their linked user account), which sits at the top of the
     * reporting hierarchy and therefore does not require a manager.
     */
    protected function isUpdatingCeo(): bool
    {
        $employee = $this->route('employee');

        if (! $employee instanceof Employee) {
            return false;
        }

        $employee->loadMissing('user');

        return (bool) $employee->user?->hasRole('ceo');
    }
}
