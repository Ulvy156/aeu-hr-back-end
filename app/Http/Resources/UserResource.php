<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @param  array<int, string>|null  $permissionNames
     */
    public function __construct(
        User $resource,
        protected ?array $permissionNames = null,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'roles' => $this->relationLoaded('roles')
                ? $this->roles->pluck('name')->sort()->values()->all()
                : [],
            'permissions' => $this->when($this->permissionNames !== null, $this->permissionNames),
            'employee' => $this->whenLoaded('employee', function () {
                if (! $this->employee) {
                    return null;
                }

                return [
                    'id' => $this->employee->id,
                    'employee_id' => $this->employee->employee_id,
                    'full_name' => $this->employee->full_name,
                    'employment_status' => $this->employee->employment_status,
                    'probation_end_date' => $this->employee->probation_end_date?->toDateString(),
                    'department' => $this->employee->relationLoaded('department')
                        ? ($this->employee->department
                            ? [
                                'id' => $this->employee->department->id,
                                'name' => $this->employee->department->name,
                            ]
                            : null)
                        : null,
                    'position' => $this->employee->relationLoaded('position')
                        ? ($this->employee->position
                            ? [
                                'id' => $this->employee->position->id,
                                'name' => $this->employee->position->name,
                            ]
                            : null)
                        : null,
                ];
            }),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
