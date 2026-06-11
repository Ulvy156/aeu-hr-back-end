<?php

namespace App\Http\Resources;

use App\Models\RecruitmentVacancy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RecruitmentVacancy
 */
class RecruitmentVacancyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'department' => $this->whenLoaded('department', fn () => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ] : null),
            'description' => $this->description,
            'required_headcount' => $this->required_headcount,
            'filled_headcount' => $this->filled_headcount,
            'target_hiring_date' => $this->target_hiring_date?->toDateString(),
            'status' => $this->status,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
