<?php

namespace App\Http\Requests\PublicHoliday;

use App\Enums\Status;
use App\Models\PublicHoliday;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePublicHolidayRequest extends FormRequest
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
            'holiday_date' => ['required', 'date_format:Y-m-d'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::enum(Status::class)],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->filled('holiday_date')) {
                    return;
                }

                $exists = PublicHoliday::query()
                    ->whereDate('holiday_date', $this->string('holiday_date')->value())
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('holiday_date', 'The holiday date has already been taken.');
                }
            },
        ];
    }
}
