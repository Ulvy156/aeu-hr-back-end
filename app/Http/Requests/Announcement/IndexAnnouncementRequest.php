<?php

namespace App\Http\Requests\Announcement;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAnnouncementRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'integer'],
            'priority' => ['nullable', Rule::in(['normal', 'important', 'urgent'])],
            'status' => ['nullable', Rule::in(['draft', 'pending_approval', 'rejected', 'published', 'archived'])],
            'created_by' => ['nullable', 'integer'],
            'read_status' => ['nullable', Rule::in(['read', 'unread'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
