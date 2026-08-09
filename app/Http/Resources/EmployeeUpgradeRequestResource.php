<?php

namespace App\Http\Resources;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeUpgradeRequest;
use App\Models\Position;
use App\Support\FileStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmployeeUpgradeRequest
 */
class EmployeeUpgradeRequestResource extends JsonResource
{
    /**
     * Model class and display-name column for each foreign-key field that
     * should be expanded from a raw id into an { id, name } snapshot.
     *
     * @var array<string, array{0: class-string<Model>, 1: string}>
     */
    protected const NAME_RESOLVABLE_FIELDS = [
        'department_id' => [Department::class, 'name'],
        'position_id' => [Position::class, 'name'],
        'manager_id' => [Employee::class, 'full_name'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'current_values' => $this->resolveValueNames($this->current_values),
            'proposed_values' => $this->resolveValueNames($this->proposed_values),
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

    /**
     * Expand department_id/position_id/manager_id from raw ids into
     * { id, name } snapshots. Other fields (base_salary, employment_status,
     * last_working_date) pass through unchanged.
     *
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    protected function resolveValueNames(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        foreach (self::NAME_RESOLVABLE_FIELDS as $field => [$modelClass, $nameColumn]) {
            if (! array_key_exists($field, $values)) {
                continue;
            }

            $values[$field] = $this->resolveNameSnapshot($modelClass, $nameColumn, $values[$field]);
        }

        return $values;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return array{id: int, name: ?string}|null
     */
    protected function resolveNameSnapshot(string $modelClass, string $nameColumn, mixed $id): ?array
    {
        if ($id === null) {
            return null;
        }

        $id = (int) $id;

        return ['id' => $id, 'name' => $modelClass::withTrashed()->whereKey($id)->value($nameColumn)];
    }
}
