<?php

namespace App\Http\Requests\RecruitmentCandidate;

use App\Services\RecruitmentCandidateService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCandidateStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in([
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
            'outcome_reason' => [
                Rule::requiredIf(in_array($this->input('status'), RecruitmentCandidateService::OUTCOME_STATUSES, true)),
                'nullable',
                'string',
            ],
        ];
    }
}
