<?php

namespace App\Http\Resources;

use App\Models\Employee;
use App\Support\FileStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'address' => $this->address,
            'join_date' => $this->join_date?->toDateString(),
            'last_working_date' => $this->last_working_date?->toDateString(),
            'base_salary' => (string) $this->base_salary,
            'employment_status' => $this->employment_status,
            'probation_end_date' => $this->probation_end_date?->toDateString(),
            'intern_end_date' => $this->intern_end_date?->toDateString(),
            'emergency_contact' => $this->emergency_contact,
            'profile_photo' => $this->profile_photo,
            'profile_photo_url' => FileStorage::url($this->profile_photo),
            'documents' => collect($this->documents ?? [])->map(fn (array $doc): array => [
                'name' => $doc['name'],
                'size' => $doc['size'],
                'url' => FileStorage::url($doc['path']),
            ])->all(),
            'user' => $this->whenLoaded('user', fn () => $this->user
                ? [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                    'status' => $this->user->status,
                ]
                : null),
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
            'manager' => $this->whenLoaded('manager', fn () => $this->manager
                ? [
                    'id' => $this->manager->id,
                    'employee_id' => $this->manager->employee_id,
                    'full_name' => $this->manager->full_name,
                ]
                : null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
