<?php

namespace App\Services;

use App\Models\PublicHoliday;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PublicHolidayService
{
    public function __construct(
        protected AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 15);

        return PublicHoliday::query()
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['year'] ?? null, fn (Builder $query, int $year) => $query->whereYear('holiday_date', $year))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('holiday_date')
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
    ): PublicHoliday {
        return DB::transaction(function () use ($data, $actor, $ipAddress, $userAgent): PublicHoliday {
            $publicHoliday = PublicHoliday::query()->create($data);

            $this->auditLogService->log(
                action: 'create',
                module: 'public_holidays',
                user: $actor,
                subject: $publicHoliday,
                newValues: $this->auditAttributes($publicHoliday),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $publicHoliday;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(
        PublicHoliday $publicHoliday,
        array $data,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): PublicHoliday {
        return DB::transaction(function () use ($publicHoliday, $data, $actor, $ipAddress, $userAgent): PublicHoliday {
            $oldValues = $this->auditAttributes($publicHoliday);

            $publicHoliday->update($data);
            $publicHoliday = $publicHoliday->fresh();

            $this->auditLogService->log(
                action: 'update',
                module: 'public_holidays',
                user: $actor,
                subject: $publicHoliday,
                oldValues: $oldValues,
                newValues: $this->auditAttributes($publicHoliday),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $publicHoliday;
        });
    }

    public function disable(
        PublicHoliday $publicHoliday,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): PublicHoliday {
        return DB::transaction(function () use ($publicHoliday, $actor, $ipAddress, $userAgent): PublicHoliday {
            $oldValues = $this->auditAttributes($publicHoliday);

            $publicHoliday->update([
                'status' => 'inactive',
            ]);
            $publicHoliday = $publicHoliday->fresh();

            $this->auditLogService->log(
                action: 'delete',
                module: 'public_holidays',
                user: $actor,
                subject: $publicHoliday,
                oldValues: $oldValues,
                newValues: $this->auditAttributes($publicHoliday),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $publicHoliday;
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function auditAttributes(PublicHoliday $publicHoliday): array
    {
        return [
            'holiday_date' => $publicHoliday->holiday_date?->toDateString(),
            'name' => $publicHoliday->name,
            'description' => $publicHoliday->description,
            'status' => $publicHoliday->status,
        ];
    }
}
