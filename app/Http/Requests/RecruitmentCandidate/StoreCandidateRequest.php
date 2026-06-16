<?php

namespace App\Http\Requests\RecruitmentCandidate;

use App\Models\RecruitmentVacancy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCandidateRequest extends FormRequest
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
            'vacancy_id' => ['required', 'integer', Rule::exists('recruitment_vacancies', 'id')],
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => [
                'required',
                'string',
                'max:50',
                Rule::unique('recruitment_candidates', 'phone')
                    ->where(fn ($query) => $query->where('vacancy_id', $this->input('vacancy_id'))),
            ],
            'email' => ['nullable', 'email', 'max:255'],
            'source' => ['required', Rule::in(['facebook', 'telegram', 'linkedin', 'referral', 'walk_in', 'email', 'other'])],
            'cv' => ['required', 'file', 'mimes:pdf', 'max:2048'],
            'interview_date' => ['nullable', 'date'],
            'interviewer_ids' => ['nullable', 'array'],
            'interviewer_ids.*' => [
                'integer',
                Rule::exists('employees', 'id')->where(fn ($query) => $query->whereNull('deleted_at')),
            ],
            'notes' => ['nullable', 'string'],
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

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $vacancyId = $this->input('vacancy_id');

                if (! $vacancyId) {
                    return;
                }

                $vacancy = RecruitmentVacancy::query()->find($vacancyId);

                if ($vacancy && $vacancy->status !== 'open') {
                    $validator->errors()->add('vacancy_id', 'Candidates can only be added to an open vacancy.');
                }
            },
        ];
    }
}
