<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecruitmentCandidate\IndexCandidateRequest;
use App\Http\Requests\RecruitmentCandidate\StoreCandidateRequest;
use App\Http\Requests\RecruitmentCandidate\UpdateCandidateRequest;
use App\Http\Requests\RecruitmentCandidate\UpdateCandidateStatusRequest;
use App\Http\Resources\RecruitmentCandidateResource;
use App\Models\RecruitmentCandidate;
use App\Services\RecruitmentCandidateService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class RecruitmentCandidateController extends Controller
{
    public function __construct(
        protected RecruitmentCandidateService $candidateService,
    ) {}

    public function index(IndexCandidateRequest $request): JsonResponse
    {
        $this->authorize('viewAny', RecruitmentCandidate::class);

        $paginator = $this->candidateService->paginate($request->validated());
        $paginator->through(fn (RecruitmentCandidate $candidate) => RecruitmentCandidateResource::make($candidate)->resolve($request));

        return ApiResponse::paginated(
            paginator: $paginator,
            data: $paginator->items(),
            message: 'Candidates fetched successfully.',
        );
    }

    public function store(StoreCandidateRequest $request): JsonResponse
    {
        $this->authorize('create', RecruitmentCandidate::class);

        $candidate = $this->candidateService->create(
            data: $request->validated(),
            cv: $request->file('cv'),
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: RecruitmentCandidateResource::make($candidate)->resolve($request),
            message: 'Candidate created successfully.',
            status: 201,
        );
    }

    public function show(RecruitmentCandidate $candidate): JsonResponse
    {
        $this->authorize('view', $candidate);

        $candidate = $this->candidateService->loadRelations($candidate);

        return ApiResponse::success(
            data: RecruitmentCandidateResource::make($candidate)->resolve(request()),
            message: 'Candidate fetched successfully.',
        );
    }

    public function update(UpdateCandidateRequest $request, RecruitmentCandidate $candidate): JsonResponse
    {
        $this->authorize('update', $candidate);

        $candidate = $this->candidateService->update(
            candidate: $candidate,
            data: $request->validated(),
            cv: $request->file('cv'),
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: RecruitmentCandidateResource::make($candidate)->resolve($request),
            message: 'Candidate updated successfully.',
        );
    }

    public function updateStatus(UpdateCandidateStatusRequest $request, RecruitmentCandidate $candidate): JsonResponse
    {
        $this->authorize('updateStatus', $candidate);

        if ($request->validated('status') === 'hired') {
            $this->authorize('hire', $candidate);
        }

        $candidate = $this->candidateService->updateStatus(
            candidate: $candidate,
            data: $request->validated(),
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: RecruitmentCandidateResource::make($candidate)->resolve($request),
            message: 'Candidate status updated successfully.',
        );
    }
}
