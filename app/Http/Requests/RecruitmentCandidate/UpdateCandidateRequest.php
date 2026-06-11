<?php

namespace App\Http\Requests\RecruitmentCandidate;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCandidateRequest extends FormRequest
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
        $candidate = $this->route('candidate');

        return [
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => [
                'required',
                'string',
                'max:50',
                Rule::unique('recruitment_candidates', 'phone')
                    ->where(fn ($query) => $query->where('vacancy_id', $candidate->vacancy_id))
                    ->ignore($candidate->id),
            ],
            'email' => ['nullable', 'email', 'max:255'],
            'source' => ['required', Rule::in(['facebook', 'telegram', 'linkedin', 'referral', 'walk_in', 'email', 'other'])],
            'cv' => ['nullable', 'file', 'mimes:pdf', 'max:2048'],
            'interview_date' => ['nullable', 'date'],
            'interviewer_id' => [
                'nullable',
                'integer',
                Rule::exists('employees', 'id')->where(fn ($query) => $query->whereNull('deleted_at')),
            ],
            'notes' => ['nullable', 'string'],
            'vacancy_id' => ['prohibited'],
            'status' => ['prohibited'],
            'outcome_reason' => ['prohibited'],
            'created_by' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.unique' => 'A candidate with this phone number already applied for this vacancy.',
        ];
    }
}
