<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\RecruitmentVacancy;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class RecruitmentVacancyService
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

        return $this->filteredQuery($filters)
            ->with(['department:id,name', 'creator:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Aggregates for the vacancy list UI. Honours search, department, and
     * target_hiring_date, but ignores status so Open/Closed counts stay stable.
     *
     * @param  array<string, mixed>  $filters
     * @return array{open_count: int, closed_count: int, open_required_headcount: int, open_filled_headcount: int, overdue_open_count: int}
     */
    public function summary(array $filters = []): array
    {
        $query = $this->filteredQuery($filters, includeStatus: false);
        $open = (clone $query)->where('status', 'open');

        $headcount = (clone $open)
            ->selectRaw('COALESCE(SUM(required_headcount), 0) as open_required_headcount')
            ->selectRaw('COALESCE(SUM(filled_headcount), 0) as open_filled_headcount')
            ->first();

        return [
            'open_count' => (clone $open)->count(),
            'closed_count' => (clone $query)->where('status', 'closed')->count(),
            'open_required_headcount' => (int) ($headcount?->open_required_headcount ?? 0),
            'open_filled_headcount' => (int) ($headcount?->open_filled_headcount ?? 0),
            'overdue_open_count' => (clone $open)
                ->whereDate('target_hiring_date', '<', now()->toDateString())
                ->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function filteredQuery(array $filters, bool $includeStatus = true): Builder
    {
        return RecruitmentVacancy::query()
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where('title', 'like', '%'.$search.'%'))
            ->when($filters['department'] ?? null, fn (Builder $query, $department) => $query->where('department_id', $department))
            ->when(
                $includeStatus && ($filters['status'] ?? null),
                fn (Builder $query) => $query->where('status', $filters['status']),
            )
            ->when($filters['target_hiring_date'] ?? null, fn (Builder $query, $date) => $query->whereDate('target_hiring_date', $date));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(
        array $data,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): RecruitmentVacancy {
        return DB::transaction(function () use ($data, $actor, $ipAddress, $userAgent): RecruitmentVacancy {
            $vacancy = RecruitmentVacancy::query()->create([
                'title' => $data['title'],
                'department_id' => $data['department_id'],
                'description' => $data['description'],
                'required_headcount' => $data['required_headcount'],
                'filled_headcount' => 0,
                'target_hiring_date' => $data['target_hiring_date'],
                'status' => 'open',
                'created_by' => $actor->id,
            ]);

            $vacancy->load(['department:id,name', 'creator:id,name']);

            $this->auditLogService->log(
                action: 'create',
                module: 'recruitment_vacancies',
                user: $actor,
                subject: $vacancy,
                newValues: $this->auditAttributes($vacancy),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $vacancy;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(
        RecruitmentVacancy $vacancy,
        array $data,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): RecruitmentVacancy {
        return DB::transaction(function () use ($vacancy, $data, $actor, $ipAddress, $userAgent): RecruitmentVacancy {
            $vacancy = RecruitmentVacancy::query()->whereKey($vacancy->id)->lockForUpdate()->firstOrFail();
            $oldValues = $this->auditAttributes($vacancy);

            $vacancy->update([
                'title' => $data['title'],
                'department_id' => $data['department_id'],
                'description' => $data['description'],
                'required_headcount' => $data['required_headcount'],
                'target_hiring_date' => $data['target_hiring_date'],
            ]);

            $vacancy = $vacancy->fresh(['department:id,name', 'creator:id,name']);

            $this->auditLogService->log(
                action: 'update',
                module: 'recruitment_vacancies',
                user: $actor,
                subject: $vacancy,
                oldValues: $oldValues,
                newValues: $this->auditAttributes($vacancy),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $vacancy;
        });
    }

    public function close(
        RecruitmentVacancy $vacancy,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): RecruitmentVacancy {
        return DB::transaction(function () use ($vacancy, $actor, $ipAddress, $userAgent): RecruitmentVacancy {
            $vacancy = RecruitmentVacancy::query()->whereKey($vacancy->id)->lockForUpdate()->firstOrFail();

            if ($vacancy->status === 'closed') {
                throw ApiException::unprocessable('This vacancy is already closed.');
            }

            $oldValues = $this->auditAttributes($vacancy);

            $vacancy->update(['status' => 'closed']);

            $vacancy = $vacancy->fresh(['department:id,name', 'creator:id,name']);

            $this->auditLogService->log(
                action: 'close',
                module: 'recruitment_vacancies',
                user: $actor,
                subject: $vacancy,
                oldValues: $oldValues,
                newValues: $this->auditAttributes($vacancy),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $vacancy;
        });
    }

    public function loadRelations(RecruitmentVacancy $vacancy): RecruitmentVacancy
    {
        return $vacancy->load(['department:id,name', 'creator:id,name']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function auditAttributes(RecruitmentVacancy $vacancy): array
    {
        return [
            'title' => $vacancy->title,
            'department_id' => $vacancy->department_id,
            'required_headcount' => $vacancy->required_headcount,
            'filled_headcount' => $vacancy->filled_headcount,
            'target_hiring_date' => $vacancy->target_hiring_date?->toDateString(),
            'status' => $vacancy->status,
        ];
    }
}
