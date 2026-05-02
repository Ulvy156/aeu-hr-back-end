<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class AuthUserResource extends JsonResource
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
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $roles = $this->roleNames !== []
            ? $this->roleNames
            : ($this->relationLoaded('roles')
                ? $this->roles->pluck('name')->values()->all()
                : []);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'roles' => $roles,
            'permissions' => $this->permissionNames,
            'employee' => $this->relationLoaded('employee') && $this->employee
                ? [
                    'id' => $this->employee->id,
                    'employee_id' => $this->employee->employee_id,
                    'full_name' => $this->employee->full_name,
                ]
                : null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
