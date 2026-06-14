<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\FileStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class ProfileResource extends JsonResource
{
    /**
     * @param  array<int, string>  $roleNames
     * @param  array<int, string>  $permissionNames
     */
    public function __construct(
        User $resource,
        protected array $roleNames = [],
        protected array $permissionNames = [],
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $roles = $this->roleNames !== []
            ? $this->roleNames
            : ($this->relationLoaded('roles')
                ? $this->roles->pluck('name')->sort()->values()->all()
                : []);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'roles' => $roles,
            'permissions' => $this->permissionNames,
            'employee' => $this->whenLoaded('employee', function (): ?array {
                if (! $this->employee) {
                    return null;
                }

                return [
                    'id' => $this->employee->id,
                    'employee_id' => $this->employee->employee_id,
                    'full_name' => $this->employee->full_name,
                    'gender' => $this->employee->gender,
                    'date_of_birth' => $this->employee->date_of_birth?->toDateString(),
                    'phone_number' => $this->employee->phone_number,
                    'email' => $this->email,
                    'address' => $this->employee->address,
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
                    'join_date' => $this->employee->join_date?->toDateString(),
                    'last_working_date' => $this->employee->last_working_date?->toDateString(),
                    'employment_status' => $this->employee->employment_status,
                    'probation_end_date' => $this->employee->probation_end_date?->toDateString(),
                    'profile_photo_url' => FileStorage::url($this->employee->profile_photo),
                ];
            }),
        ];
    }
}
