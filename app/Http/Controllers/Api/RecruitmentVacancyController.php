<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecruitmentVacancy\IndexVacancyRequest;
use App\Http\Requests\RecruitmentVacancy\StoreVacancyRequest;
use App\Http\Requests\RecruitmentVacancy\UpdateVacancyRequest;
use App\Http\Resources\RecruitmentVacancyResource;
use App\Models\RecruitmentVacancy;
use App\Services\RecruitmentVacancyService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class RecruitmentVacancyController extends Controller
{
    public function __construct(
        protected RecruitmentVacancyService $vacancyService,
    ) {}

    public function index(IndexVacancyRequest $request): JsonResponse
    {
        $this->authorize('viewAny', RecruitmentVacancy::class);

        $paginator = $this->vacancyService->paginate($request->validated());
        $paginator->through(fn (RecruitmentVacancy $vacancy) => RecruitmentVacancyResource::make($vacancy)->resolve($request));

        return ApiResponse::paginated(
            paginator: $paginator,
            data: $paginator->items(),
            message: 'Vacancies fetched successfully.',
        );
    }

    public function store(StoreVacancyRequest $request): JsonResponse
    {
        $this->authorize('create', RecruitmentVacancy::class);

        $vacancy = $this->vacancyService->create(
            data: $request->validated(),
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: RecruitmentVacancyResource::make($vacancy)->resolve($request),
            message: 'Vacancy created successfully.',
            status: 201,
        );
    }

    public function show(RecruitmentVacancy $vacancy): JsonResponse
    {
        $this->authorize('view', $vacancy);

        $vacancy = $this->vacancyService->loadRelations($vacancy);

        return ApiResponse::success(
            data: RecruitmentVacancyResource::make($vacancy)->resolve(request()),
            message: 'Vacancy fetched successfully.',
        );
    }

    public function update(UpdateVacancyRequest $request, RecruitmentVacancy $vacancy): JsonResponse
    {
        $this->authorize('update', $vacancy);

        $vacancy = $this->vacancyService->update(
            vacancy: $vacancy,
            data: $request->validated(),
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: RecruitmentVacancyResource::make($vacancy)->resolve($request),
            message: 'Vacancy updated successfully.',
        );
    }

    public function close(RecruitmentVacancy $vacancy): JsonResponse
    {
        $this->authorize('close', $vacancy);

        $vacancy = $this->vacancyService->close(
            vacancy: $vacancy,
            actor: request()->user(),
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
        );

        return ApiResponse::success(
            data: RecruitmentVacancyResource::make($vacancy)->resolve(request()),
            message: 'Vacancy closed successfully.',
        );
    }
}
