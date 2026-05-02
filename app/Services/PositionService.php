<?php

namespace App\Services;

use App\Models\Position;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class PositionService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 15);

        return Position::query()
            ->with(['department'])
            ->withCount('employees')
            ->when($filters['department_id'] ?? null, fn ($query, $departmentId) => $query->where('department_id', $departmentId))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where('name', 'like', '%'.$search.'%');
            })
            ->orderBy('name')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Position
    {
        return Position::query()->create($data)->load('department');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Position $position, array $data): Position
    {
        $position->update($data);

        return $position->fresh(['department']);
    }

    public function delete(Position $position): void
    {
        $position->loadCount('employees');

        if ($position->employees_count > 0) {
            throw ValidationException::withMessages([
                'position' => ['Position cannot be deleted while it is assigned to employees.'],
            ]);
        }

        $position->delete();
    }
}
