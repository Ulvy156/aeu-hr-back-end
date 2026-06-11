<?php

namespace App\Http\Requests\RecruitmentCandidate;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexCandidateRequest extends FormRequest
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
            'vacancy' => ['nullable', 'integer'],
            'source' => ['nullable', Rule::in(['facebook', 'telegram', 'linkedin', 'referral', 'walk_in', 'email', 'other'])],
            'status' => ['nullable', Rule::in([
                'new',
                'shortlisted',
                'contacting_candidate',
                'interview',
                'offer_extended',
                'offer_accepted',
                'hired',
                'company_rejected',
                'candidate_declined',
                'no_show',
            ])],
            'interview_date' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
