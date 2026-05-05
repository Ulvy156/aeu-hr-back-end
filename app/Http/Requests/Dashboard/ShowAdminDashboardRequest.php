<?php

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

class ShowAdminDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('dashboards.admin_view') ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }
}
