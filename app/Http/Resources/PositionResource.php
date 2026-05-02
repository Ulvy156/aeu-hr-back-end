<?php

namespace App\Http\Resources;

use App\Models\Position;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Position
 */
class PositionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'department' => $this->whenLoaded('department', fn () => $this->department
                ? [
                    'id' => $this->department->id,
                    'name' => $this->department->name,
                    'status' => $this->department->status,
                ]
                : null),
            'employees_count' => $this->whenCounted('employees'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
