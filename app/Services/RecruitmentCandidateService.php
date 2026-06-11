<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentVacancy;
use App\Models\User;
use App\Support\FileStorage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class RecruitmentCandidateService
{
    /**
     * Statuses that require an outcome_reason.
     *
     * @var array<int, string>
     */
    public const OUTCOME_STATUSES = ['company_rejected', 'candidate_declined', 'no_show'];

    public function __construct(
        protected AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 15);

        return RecruitmentCandidate::query()
            ->with(['vacancy:id,title,status', 'creator:id,name', 'interviewer:id,employee_id,full_name'])
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $query->where(function (Builder $inner) use ($search) {
                    $inner->where('full_name', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->when($filters['vacancy'] ?? null, fn (Builder $query, $vacancy) => $query->where('vacancy_id', $vacancy))
            ->when($filters['source'] ?? null, fn (Builder $query, $source) => $query->where('source', $source))
            ->when($filters['status'] ?? null, fn (Builder $query, $status) => $query->where('status', $status))
            ->when($filters['interview_date'] ?? null, fn (Builder $query, $date) => $query->whereDate('interview_date', $date))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(
        array $data,
        UploadedFile $cv,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): RecruitmentCandidate {
        return DB::transaction(function () use ($data, $cv, $actor, $ipAddress, $userAgent): RecruitmentCandidate {
            $candidate = RecruitmentCandidate::query()->create([
                'vacancy_id' => $data['vacancy_id'],
                'full_name' => $data['full_name'],
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'source' => $data['source'],
                'status' => 'new',
                'interview_date' => $data['interview_date'] ?? null,
                'interviewer_id' => $data['interviewer_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
                ...$this->storeCv($cv),
            ]);

            $candidate->load(['vacancy:id,title,status', 'creator:id,name', 'interviewer:id,employee_id,full_name']);

            $this->auditLogService->log(
                action: 'create',
                module: 'recruitment_candidates',
                user: $actor,
                subject: $candidate,
                newValues: $this->auditAttributes($candidate),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $candidate;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(
        RecruitmentCandidate $candidate,
        array $data,
        ?UploadedFile $cv,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): RecruitmentCandidate {
        return DB::transaction(function () use ($candidate, $data, $cv, $actor, $ipAddress, $userAgent): RecruitmentCandidate {
            $candidate = RecruitmentCandidate::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();

            if ($candidate->status === 'hired') {
                throw ApiException::unprocessable('Hired candidates are read-only and cannot be updated.');
            }

            $oldValues = $this->auditAttributes($candidate);

            $attributes = [
                'full_name' => $data['full_name'],
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'source' => $data['source'],
                'interview_date' => $data['interview_date'] ?? null,
                'interviewer_id' => $data['interviewer_id'] ?? null,
                'notes' => $data['notes'] ?? null,
            ];

            if ($cv) {
                FileStorage::disk()->delete($candidate->cv_path);
                $attributes = array_merge($attributes, $this->storeCv($cv));
            }

            $candidate->update($attributes);

            $candidate = $candidate->fresh(['vacancy:id,title,status', 'creator:id,name', 'interviewer:id,employee_id,full_name']);

            $this->auditLogService->log(
                action: 'update',
                module: 'recruitment_candidates',
                user: $actor,
                subject: $candidate,
                oldValues: $oldValues,
                newValues: $this->auditAttributes($candidate),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $candidate;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateStatus(
        RecruitmentCandidate $candidate,
        array $data,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): RecruitmentCandidate {
        return DB::transaction(function () use ($candidate, $data, $actor, $ipAddress, $userAgent): RecruitmentCandidate {
            $candidate = RecruitmentCandidate::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();

            if ($candidate->status === 'hired') {
                throw ApiException::unprocessable('Hired candidates are read-only and cannot change status.');
            }

            $oldValues = $this->auditAttributes($candidate);
            $newStatus = $data['status'];

            $candidate->update([
                'status' => $newStatus,
                'outcome_reason' => in_array($newStatus, self::OUTCOME_STATUSES, true)
                    ? $data['outcome_reason']
                    : null,
            ]);

            if ($newStatus === 'hired') {
                RecruitmentVacancy::query()
                    ->whereKey($candidate->vacancy_id)
                    ->lockForUpdate()
                    ->increment('filled_headcount');
            }

            $candidate = $candidate->fresh(['vacancy:id,title,status', 'creator:id,name', 'interviewer:id,employee_id,full_name']);

            $this->auditLogService->log(
                action: 'status_change',
                module: 'recruitment_candidates',
                user: $actor,
                subject: $candidate,
                oldValues: $oldValues,
                newValues: $this->auditAttributes($candidate),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            if ($newStatus === 'hired') {
                $this->auditLogService->log(
                    action: 'hire',
                    module: 'recruitment_candidates',
                    user: $actor,
                    subject: $candidate,
                    oldValues: $oldValues,
                    newValues: $this->auditAttributes($candidate),
                    ipAddress: $ipAddress,
                    userAgent: $userAgent,
                );
            }

            return $candidate;
        });
    }

    public function loadRelations(RecruitmentCandidate $candidate): RecruitmentCandidate
    {
        return $candidate->load(['vacancy:id,title,status', 'creator:id,name', 'interviewer:id,employee_id,full_name']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function storeCv(UploadedFile $file): array
    {
        return [
            'cv_path' => FileStorage::disk()->putFile('recruitment-cvs', $file),
            'cv_name' => $file->getClientOriginalName(),
            'cv_size' => $file->getSize(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function auditAttributes(RecruitmentCandidate $candidate): array
    {
        return [
            'vacancy_id' => $candidate->vacancy_id,
            'full_name' => $candidate->full_name,
            'phone' => $candidate->phone,
            'email' => $candidate->email,
            'source' => $candidate->source,
            'status' => $candidate->status,
            'cv_name' => $candidate->cv_name,
            'interview_date' => $candidate->interview_date?->toDateString(),
            'outcome_reason' => $candidate->outcome_reason,
        ];
    }
}
