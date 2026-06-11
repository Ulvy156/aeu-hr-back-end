<?php

namespace App\Http\Requests\Announcement;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Spatie\Permission\Models\Role;

class StoreAnnouncementRequest extends FormRequest
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
            'category_id' => ['required', 'integer', Rule::exists('announcement_categories', 'id')->where('status', 'active')],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'priority' => ['nullable', Rule::in(['normal', 'important', 'urgent'])],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:2048'],
            'targets' => ['required', 'array', 'min:1'],
            'targets.*.target_type' => ['required', Rule::in(['all', 'role', 'department', 'employee'])],
            'targets.*.target_id' => ['nullable', 'integer'],
            'status' => ['prohibited'],
            'created_by' => ['prohibited'],
            'submitted_by' => ['prohibited'],
            'submitted_at' => ['prohibited'],
            'approved_by' => ['prohibited'],
            'approved_at' => ['prohibited'],
            'rejected_by' => ['prohibited'],
            'rejected_at' => ['prohibited'],
            'rejection_reason' => ['prohibited'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ((array) $this->input('targets', []) as $index => $target) {
                    $type = $target['target_type'] ?? null;
                    $id = $target['target_id'] ?? null;

                    if ($type === 'all') {
                        continue;
                    }

                    if ($id === null) {
                        $validator->errors()->add("targets.{$index}.target_id", 'The target_id field is required for this target type.');

                        continue;
                    }

                    $exists = match ($type) {
                        'role' => Role::query()->whereKey($id)->exists(),
                        'department' => Department::query()->whereKey($id)->exists(),
                        'employee' => Employee::query()->whereKey($id)->exists(),
                        default => true,
                    };

                    if (! $exists) {
                        $validator->errors()->add("targets.{$index}.target_id", 'The selected target_id is invalid.');
                    }
                }
            },
        ];
    }
}
