<?php

namespace App\Http\Resources;

use App\Models\RecruitmentCandidate;
use App\Support\FileStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RecruitmentCandidate
 */
class RecruitmentCandidateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vacancy' => $this->whenLoaded('vacancy', fn () => $this->vacancy ? [
                'id' => $this->vacancy->id,
                'title' => $this->vacancy->title,
                'status' => $this->vacancy->status,
            ] : null),
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'source' => $this->source,
            'cv' => [
                'name' => $this->cv_name,
                'size' => $this->cv_size,
                'url' => FileStorage::url($this->cv_path),
            ],
            'status' => $this->status,
            'interview_date' => $this->interview_date?->toDateString(),
            'interviewers' => $this->whenLoaded('interviewers', fn () => $this->interviewers->map(fn ($interviewer) => [
                'id' => $interviewer->id,
                'employee_id' => $interviewer->employee_id,
                'full_name' => $interviewer->full_name,
            ])->all()),
            'notes' => $this->notes,
            'outcome_reason' => $this->outcome_reason,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
