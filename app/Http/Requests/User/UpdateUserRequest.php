<?php

namespace App\Http\Requests\User;

use App\Enums\Status;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
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
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'status' => ['required', Rule::enum(Status::class)],
            'roles' => ['sometimes', 'array', 'size:1'],
            'roles.*' => ['required_with:roles', 'string', 'distinct', 'exists:roles,name'],
        ];
    }
}
