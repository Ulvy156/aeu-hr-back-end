<?php

namespace App\Http\Resources;

use App\Models\EmployeeUpgradeRequest;
use App\Support\FileStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmployeeUpgradeRequest
 */
class EmployeeUpgradeRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'current_values' => $this->current_values,
            'proposed_values' => $this->proposed_values,
            'effective_date' => $this->effective_date?->toDateString(),
            'rejection_reason' => $this->rejection_reason,
            'attachments' => collect($this->attachments ?? [])->map(fn (array $attachment): array => [
                'name' => $attachment['name'],
                'size' => $attachment['size'],
                'url' => FileStorage::url($attachment['path']),
            ])->all(),
            'employee' => $this->whenLoaded('employee', fn () => $this->employee
                ? [
                    'id' => $this->employee->id,
                    'employee_id' => $this->employee->employee_id,
                    'full_name' => $this->employee->full_name,
                ]
                : null),
            'requested_by' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy
                ? [
                    'id' => $this->requestedBy->id,
                    'name' => $this->requestedBy->name,
                ]
                : null),
            'reviewed_by' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy
                ? [
                    'id' => $this->reviewedBy->id,
                    'name' => $this->reviewedBy->name,
                ]
                : null),
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
