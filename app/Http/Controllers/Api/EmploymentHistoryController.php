<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmploymentHistory\IndexEmploymentHistoryRequest;
use App\Http\Resources\EmploymentHistoryResource;
use App\Models\Employee;
use App\Models\EmploymentHistory;
use App\Services\EmploymentHistoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class EmploymentHistoryController extends Controller
{
    public function __construct(
        protected EmploymentHistoryService $employmentHistoryService,
    ) {}

    public function index(IndexEmploymentHistoryRequest $request, Employee $employee): JsonResponse
    {
        $this->authorize('view', $employee);

        $paginator = $this->employmentHistoryService->paginate($employee, $request->validated());
        $paginator->through(fn (EmploymentHistory $history) => EmploymentHistoryResource::make($history)->resolve($request));

        return ApiResponse::paginated(
            paginator: $paginator,
            data: $paginator->items(),
            message: 'Employment history fetched successfully.',
        );
    }
}
