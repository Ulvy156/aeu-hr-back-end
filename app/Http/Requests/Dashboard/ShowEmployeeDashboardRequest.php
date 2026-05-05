<?php

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

class ShowEmployeeDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('dashboards.employee_view') ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }
}
