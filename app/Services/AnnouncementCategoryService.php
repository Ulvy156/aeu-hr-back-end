<?php

namespace App\Services;

use App\Enums\Status;
use App\Models\AnnouncementCategory;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AnnouncementCategoryService
{
    public function __construct(
        protected AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 15);

        return AnnouncementCategory::query()
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(
        array $data,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): AnnouncementCategory {
        return DB::transaction(function () use ($data, $actor, $ipAddress, $userAgent): AnnouncementCategory {
            $category = AnnouncementCategory::query()->create($data);

            $this->auditLogService->log(
                action: 'create',
                module: 'announcement_categories',
                user: $actor,
                subject: $category,
                newValues: $this->auditAttributes($category),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $category;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(
        AnnouncementCategory $category,
        array $data,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): AnnouncementCategory {
        return DB::transaction(function () use ($category, $data, $actor, $ipAddress, $userAgent): AnnouncementCategory {
            $oldValues = $this->auditAttributes($category);

            $category->update($data);

            $this->auditLogService->log(
                action: 'update',
                module: 'announcement_categories',
                user: $actor,
                subject: $category,
                oldValues: $oldValues,
                newValues: $this->auditAttributes($category),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $category->fresh();
        });
    }

    public function deactivate(
        AnnouncementCategory $category,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): AnnouncementCategory {
        return DB::transaction(function () use ($category, $actor, $ipAddress, $userAgent): AnnouncementCategory {
            if ($category->status === Status::Inactive) {
                return $category;
            }

            $oldValues = $this->auditAttributes($category);

            $category->update(['status' => Status::Inactive->value]);

            $this->auditLogService->log(
                action: 'deactivate',
                module: 'announcement_categories',
                user: $actor,
                subject: $category,
                oldValues: $oldValues,
                newValues: $this->auditAttributes($category),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $category;
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function auditAttributes(AnnouncementCategory $category): array
    {
        return [
            'name' => $category->name,
            'description' => $category->description,
            'status' => $category->status->value,
        ];
    }
}
