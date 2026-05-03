<?php

namespace App\Http\Requests\CompanySetting;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCompanySettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        if ($this->exists('salary_currency')) {
            $normalized['salary_currency'] = $this->filled('salary_currency')
                ? strtoupper((string) $this->input('salary_currency'))
                : $this->input('salary_currency');
        }

        if ($this->exists('working_days')) {
            $workingDays = $this->input('working_days');

            $normalized['working_days'] = is_array($workingDays)
                ? array_map(
                    fn (mixed $day) => is_string($day) ? strtolower($day) : $day,
                    $workingDays,
                )
                : $workingDays;
        }

        if ($this->exists('working_start_time')) {
            $normalized['working_start_time'] = $this->normalizeTime($this->input('working_start_time'));
        }

        if ($this->exists('working_end_time')) {
            $normalized['working_end_time'] = $this->normalizeTime($this->input('working_end_time'));
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'company_name' => ['sometimes', 'required', 'string', 'max:255'],
            'company_logo' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp,svg', 'max:2048'],
            'company_address' => ['sometimes', 'nullable', 'string'],
            'company_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'company_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'office_latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'office_longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'allowed_radius_meters' => ['sometimes', 'required', 'integer', 'min:1', 'max:100000'],
            'working_start_time' => ['sometimes', 'required', 'date_format:H:i:s'],
            'working_end_time' => ['sometimes', 'required', 'date_format:H:i:s'],
            'working_days' => ['sometimes', 'required', 'array', 'min:1', 'max:7'],
            'working_days.*' => [
                'required',
                'string',
                'distinct',
                Rule::in(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']),
            ],
            'salary_currency' => ['sometimes', 'required', 'string', 'size:3', 'alpha'],
            'payroll_day_rate' => ['sometimes', 'required', 'integer', 'min:1', 'max:31'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->has('office_latitude') xor $this->has('office_longitude')) {
                    $validator->errors()->add(
                        $this->has('office_latitude') ? 'office_longitude' : 'office_latitude',
                        'Office latitude and longitude must be provided together.',
                    );
                }

                $startTime = $this->input('working_start_time');
                $endTime = $this->input('working_end_time');

                if ($startTime && $endTime && $startTime >= $endTime) {
                    $validator->errors()->add('working_end_time', 'The working end time must be after the working start time.');
                }
            },
        ];
    }

    protected function normalizeTime(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        if (preg_match('/^\d{2}:\d{2}$/', $value) === 1) {
            return $value.':00';
        }

        return $value;
    }
}
