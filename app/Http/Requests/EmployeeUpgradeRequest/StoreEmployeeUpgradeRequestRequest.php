<?php

namespace App\Http\Requests\EmployeeUpgradeRequest;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\EmployeeUpgradeRequest;
use App\Models\Position;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreEmployeeUpgradeRequestRequest extends FormRequest
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
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'effective_date' => ['nullable', 'date'],
            'proposed_values' => ['required', 'array', 'min:1'],
            'proposed_values.department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'proposed_values.position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'proposed_values.manager_id' => ['nullable', 'integer', 'exists:employees,id'],
            'proposed_values.base_salary' => ['numeric', 'min:0'],
            'proposed_values.employment_status' => [Rule::enum(EmploymentStatus::class)],
            'proposed_values.last_working_date' => ['nullable', 'date'],
            'attachments' => ['nullable', 'array', 'max:3'],
            'attachments.*' => ['file', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx', 'max:5120'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $proposedValues = $this->input('proposed_values', []);

                if (! is_array($proposedValues)) {
                    return;
                }

                $unknownKeys = array_diff(
                    array_keys($proposedValues),
                    [...EmployeeUpgradeRequest::UPGRADABLE_FIELDS, 'last_working_date'],
                );

                foreach ($unknownKeys as $unknownKey) {
                    $validator->errors()->add("proposed_values.{$unknownKey}", "Unknown field [{$unknownKey}] in proposed_values.");
                }

                if (array_intersect(array_keys($proposedValues), EmployeeUpgradeRequest::UPGRADABLE_FIELDS) === []) {
                    $validator->errors()->add('proposed_values', 'At least one of department_id, position_id, base_salary, employment_status, or manager_id must be proposed.');

                    return;
                }

                $employmentStatus = $proposedValues['employment_status'] ?? null;
                $lastWorkingDate = $proposedValues['last_working_date'] ?? null;

                if (in_array($employmentStatus, [EmploymentStatus::FullTime->value, EmploymentStatus::Probation->value], true) && $lastWorkingDate) {
                    $validator->errors()->add('proposed_values.last_working_date', 'Active or probation employment status must not have a last working date.');
                }

                if (in_array($employmentStatus, [EmploymentStatus::Resigned->value, EmploymentStatus::Terminated->value], true) && ! $lastWorkingDate) {
                    $validator->errors()->add('proposed_values.last_working_date', 'Resigned or terminated employment status must have a last working date.');
                }

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $employee = Employee::query()->find($this->integer('employee_id'));

                if (! $employee) {
                    return;
                }

                if (! $this->proposedValuesDiffer($employee, $proposedValues)) {
                    $validator->errors()->add('proposed_values', "At least one proposed value must differ from the employee's current data.");

                    return;
                }

                if (array_key_exists('manager_id', $proposedValues) && $proposedValues['manager_id'] !== null) {
                    $proposedManagerId = (int) $proposedValues['manager_id'];

                    if ($proposedManagerId === $employee->id) {
                        $validator->errors()->add('proposed_values.manager_id', 'An employee cannot be their own manager.');
                    } else {
                        $visited = [];
                        $currentId = $proposedManagerId;

                        for ($i = 0; $i < 50; $i++) {
                            if ($currentId === $employee->id) {
                                $validator->errors()->add('proposed_values.manager_id', 'This change would create a circular reporting relationship.');

                                break;
                            }

                            if (in_array($currentId, $visited, true)) {
                                break;
                            }

                            $visited[] = $currentId;

                            $managerId = Employee::query()->whereKey($currentId)->value('manager_id');

                            if ($managerId === null) {
                                break;
                            }

                            $currentId = (int) $managerId;
                        }
                    }
                }

                $resultingDepartmentId = array_key_exists('department_id', $proposedValues)
                    ? $proposedValues['department_id']
                    : $employee->department_id;

                $resultingPositionId = array_key_exists('position_id', $proposedValues)
                    ? $proposedValues['position_id']
                    : $employee->position_id;

                if (! $resultingPositionId) {
                    return;
                }

                $position = Position::query()->find($resultingPositionId);

                if ($position && $position->department_id && $resultingDepartmentId && (int) $position->department_id !== (int) $resultingDepartmentId) {
                    $validator->errors()->add('proposed_values.position_id', 'The selected position does not belong to the selected department.');
                }
            },
        ];
    }

    /**
     * @param  array<string, mixed>  $proposedValues
     */
    protected function proposedValuesDiffer(Employee $employee, array $proposedValues): bool
    {
        foreach (EmployeeUpgradeRequest::UPGRADABLE_FIELDS as $field) {
            if (! array_key_exists($field, $proposedValues)) {
                continue;
            }

            $current = $employee->getAttribute($field);
            $proposed = $proposedValues[$field];

            $differs = match ($field) {
                'department_id', 'position_id', 'manager_id' => $this->normalizeId($current) !== $this->normalizeId($proposed),
                'base_salary' => number_format((float) $current, 2, '.', '') !== number_format((float) $proposed, 2, '.', ''),
                'employment_status' => ($current instanceof EmploymentStatus ? $current->value : $current) !== $proposed,
                default => $current !== $proposed,
            };

            if ($differs) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeId(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
