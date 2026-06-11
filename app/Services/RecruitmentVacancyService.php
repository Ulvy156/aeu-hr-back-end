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

        return RecruitmentVacancy::query()
            ->with(['department:id,name', 'creator:id,name'])
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where('title', 'like', '%'.$search.'%'))
            ->when($filters['department'] ?? null, fn (Builder $query, $department) => $query->where('department_id', $department))
            ->when($filters['status'] ?? null, fn (Builder $query, $status) => $query->where('status', $status))
            ->when($filters['target_hiring_date'] ?? null, fn (Builder $query, $date) => $query->whereDate('target_hiring_date', $date))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
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
