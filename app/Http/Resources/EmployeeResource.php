<?php

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin Employee
 */
class EmployeeResource extends JsonResource
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
            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'phone_number' => $this->phone_number,
            'email' => $this->email,
            'address' => $this->address,
            'join_date' => $this->join_date?->toDateString(),
            'last_working_date' => $this->last_working_date?->toDateString(),
            'base_salary' => (string) $this->base_salary,
            'employment_status' => $this->employment_status,
            'emergency_contact' => $this->emergency_contact,
            'profile_photo' => $this->profile_photo,
            'profile_photo_url' => $this->profile_photo ? Storage::disk('public')->url($this->profile_photo) : null,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
                'email' => $this->user?->email,
                'status' => $this->user?->status,
                'roles' => $this->user?->relationLoaded('roles')
                    ? $this->user->roles->pluck('name')->values()->all()
                    : [],
            ]),
            'department' => $this->whenLoaded('department', fn () => $this->department
                ? [
                    'id' => $this->department->id,
                    'name' => $this->department->name,
                    'status' => $this->department->status,
                ]
                : null),
            'position' => $this->whenLoaded('position', fn () => $this->position
                ? [
                    'id' => $this->position->id,
                    'name' => $this->position->name,
                    'status' => $this->position->status,
                ]
                : null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
