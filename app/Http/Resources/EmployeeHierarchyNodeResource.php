<?php

namespace App\Http\Resources;

use App\Models\Employee;
use App\Support\FileStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Employee
 */
class EmployeeHierarchyNodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'full_name' => $this->full_name,
            'profile_photo_url' => FileStorage::url($this->profile_photo),
            'department' => $this->whenLoaded('department', fn () => $this->department
                ? [
                    'id' => $this->department->id,
                    'name' => $this->department->name,
                ]
                : null),
            'position' => $this->whenLoaded('position', fn () => $this->position
                ? [
                    'id' => $this->position->id,
                    'name' => $this->position->name,
                ]
                : null),
            'children' => EmployeeHierarchyNodeResource::collection($this->children ?? []),
        ];
    }
}
