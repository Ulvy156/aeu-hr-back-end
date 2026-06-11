<?php

namespace App\Http\Requests\AnnouncementCategory;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAnnouncementCategoryRequest extends FormRequest
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
        $categoryId = $this->route('announcement_category')?->id;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('announcement_categories', 'name')->ignore($categoryId)],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }
}
