<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentHistory;
use App\Models\Position;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class EmploymentHistoryService
{
    /**
     * Record an employment_histories row for each tracked field that changed.
     *
     * @param  array<string, mixed>  $oldAttributes  snapshot of EmploymentHistory::TRACKED_FIELDS captured
     *                                               from $employee before it was updated
     */
    public function recordChanges(
        Employee $employee,
        array $oldAttributes,
        ?Department $oldDepartment,
        ?Position $oldPosition,
        ?Employee $oldManager,
        ?User $actor,
        ?string $effectiveDate = null,
    ): void {
        $effectiveDate ??= now()->toDateString();
        $rows = [];

        foreach (EmploymentHistory::TRACKED_FIELDS as $field) {
            $old = $oldAttributes[$field];
            $new = $employee->getAttribute($field);

            if (! $this->hasChanged($field, $old, $new)) {
                continue;
            }

            $oldSnapshot = $this->resolveValue($field, $old, $employee, $oldDepartment, $oldPosition, $oldManager, isOld: true);
            $newSnapshot = $this->resolveValue($field, $new, $employee, $oldDepartment, $oldPosition, $oldManager, isOld: false);

            $rows[] = [
                'employee_id' => $employee->id,
                'field' => $field,
                'old_value' => $oldSnapshot === null ? null : json_encode($oldSnapshot, JSON_PRESERVE_ZERO_FRACTION),
                'new_value' => json_encode($newSnapshot, JSON_PRESERVE_ZERO_FRACTION),
                'effective_date' => $effectiveDate,
                'changed_by' => $actor?->id,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            DB::table('employment_histories')->insert($rows);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(Employee $employee, array $filters = []): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 15);

        return EmploymentHistory::query()
            ->where('employee_id', $employee->id)
            ->with('changedBy:id,name')
            ->when($filters['field'] ?? null, fn (Builder $query, string $field) => $query->where('field', $field))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('effective_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('effective_date', '<=', $date))
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    protected function hasChanged(string $field, mixed $old, mixed $new): bool
    {
        return match ($field) {
            EmploymentHistory::FIELD_DEPARTMENT_ID, EmploymentHistory::FIELD_POSITION_ID, EmploymentHistory::FIELD_MANAGER_ID => $this->normalizeId($old) !== $this->normalizeId($new),
            EmploymentHistory::FIELD_BASE_SALARY => number_format((float) $old, 2, '.', '') !== number_format((float) $new, 2, '.', ''),
            EmploymentHistory::FIELD_PROBATION_END_DATE => $this->normalizeDate($old) !== $this->normalizeDate($new),
            default => $old !== $new,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveValue(
        string $field,
        mixed $rawValue,
        Employee $employee,
        ?Department $oldDepartment,
        ?Position $oldPosition,
        ?Employee $oldManager,
        bool $isOld,
    ): ?array {
        return match ($field) {
            EmploymentHistory::FIELD_DEPARTMENT_ID => $this->resolveDepartmentSnapshot($this->normalizeId($rawValue), $employee, $oldDepartment, $isOld),
            EmploymentHistory::FIELD_POSITION_ID => $this->resolvePositionSnapshot($this->normalizeId($rawValue), $employee, $oldPosition, $isOld),
            EmploymentHistory::FIELD_MANAGER_ID => $this->resolveManagerSnapshot($this->normalizeId($rawValue), $employee, $oldManager, $isOld),
            EmploymentHistory::FIELD_BASE_SALARY => ['value' => $rawValue === null ? null : (float) $rawValue],
            EmploymentHistory::FIELD_EMPLOYMENT_STATUS => ['value' => $rawValue],
            EmploymentHistory::FIELD_PROBATION_END_DATE => $this->resolveDateSnapshot($rawValue, $isOld),
            default => null,
        };
    }

    /**
     * @return array{value: ?string}|null
     */
    protected function resolveDateSnapshot(mixed $rawValue, bool $isOld): ?array
    {
        $normalized = $this->normalizeDate($rawValue);

        if ($isOld && $normalized === null) {
            return null;
        }

        return ['value' => $normalized];
    }

    /**
     * @return array{id: int, name: ?string}|null
     */
    protected function resolveDepartmentSnapshot(?int $id, Employee $employee, ?Department $oldDepartment, bool $isOld): ?array
    {
        if ($id === null) {
            return null;
        }

        $department = match (true) {
            $employee->department?->id === $id => $employee->department,
            $isOld && $oldDepartment?->id === $id => $oldDepartment,
            default => Department::withTrashed()->find($id),
        };

        return ['id' => $id, 'name' => $department?->name];
    }

    /**
     * @return array{id: int, name: ?string}|null
     */
    protected function resolvePositionSnapshot(?int $id, Employee $employee, ?Position $oldPosition, bool $isOld): ?array
    {
        if ($id === null) {
            return null;
        }

        $position = match (true) {
            $employee->position?->id === $id => $employee->position,
            $isOld && $oldPosition?->id === $id => $oldPosition,
            default => Position::withTrashed()->find($id),
        };

        return ['id' => $id, 'name' => $position?->name];
    }

    /**
     * @return array{id: int, name: ?string}|null
     */
    protected function resolveManagerSnapshot(?int $id, Employee $employee, ?Employee $oldManager, bool $isOld): ?array
    {
        if ($id === null) {
            return null;
        }

        $manager = match (true) {
            $employee->manager?->id === $id => $employee->manager,
            $isOld && $oldManager?->id === $id => $oldManager,
            default => Employee::withTrashed()->find($id),
        };

        return ['id' => $id, 'name' => $manager?->full_name];
    }

    protected function normalizeId(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    protected function normalizeDate(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toDateString() : $value;
    }
}
