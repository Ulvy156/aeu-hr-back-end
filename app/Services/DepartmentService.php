<?php

namespace App\Services;

use App\Models\Department;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class DepartmentService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 15);

        return Department::query()
            ->withCount(['positions', 'employees'])
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
    public function create(array $data): Department
    {
        return Department::query()->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Department $department, array $data): Department
    {
        $department->update($data);

        return $department->fresh(['positions', 'employees']);
    }

    public function delete(Department $department): void
    {
        $department->loadCount(['positions', 'employees']);

        if ($department->positions_count > 0 || $department->employees_count > 0) {
            throw ValidationException::withMessages([
                'department' => ['Department cannot be deleted while it still has positions or employees.'],
            ]);
        }

        $department->delete();
    }
}
