<?php

namespace App\Http\Requests\Payroll;

use App\Models\PayrollBatch;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePayrollRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'distinct', Rule::exists('payroll_items', 'id')],
            'items.*.base_salary' => ['sometimes', 'numeric', 'min:0'],
            'items.*.working_days' => ['sometimes', 'numeric', 'min:0'],
            'items.*.present_days' => ['sometimes', 'numeric', 'min:0'],
            'items.*.absent_days' => ['sometimes', 'numeric', 'min:0'],
            'items.*.unpaid_leave_days' => ['sometimes', 'numeric', 'min:0'],
            'items.*.tax_amount' => ['sometimes', 'numeric', 'min:0'],
            'items.*.employee_id' => ['prohibited'],
            'items.*.daily_rate' => ['prohibited'],
            'items.*.gross_salary' => ['prohibited'],
            'items.*.unpaid_deduction' => ['prohibited'],
            'items.*.absence_deduction' => ['prohibited'],
            'items.*.taxable_salary' => ['prohibited'],
            'items.*.net_salary' => ['prohibited'],
            'items.*.status' => ['prohibited'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $payroll = $this->route('payroll');

                if (! $payroll instanceof PayrollBatch) {
                    return;
                }

                $allowedItemIds = $payroll->items()->pluck('id')->all();

                foreach ((array) $this->input('items', []) as $index => $item) {
                    $itemId = $item['id'] ?? null;

                    if ($itemId === null || in_array((int) $itemId, $allowedItemIds, true)) {
                        continue;
                    }

                    $validator->errors()->add("items.{$index}.id", 'The selected payroll item does not belong to this payroll batch.');
                }
            },
        ];
    }
}
