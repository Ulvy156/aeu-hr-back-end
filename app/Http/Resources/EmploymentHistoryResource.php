<?php

namespace App\Http\Resources;

use App\Models\EmploymentHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmploymentHistory
 */
class EmploymentHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'field' => $this->field,
            'old_value' => $this->old_value,
            'new_value' => $this->new_value,
            'effective_date' => $this->effective_date?->toDateString(),
            'changed_by' => $this->whenLoaded('changedBy', fn () => $this->changedBy
                ? [
                    'id' => $this->changedBy->id,
                    'name' => $this->changedBy->name,
                ]
                : null),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
