<?php

namespace App\Http\Requests\EmployeeUpgradeRequest;

use Illuminate\Foundation\Http\FormRequest;

class ApproveEmployeeUpgradeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }
}
