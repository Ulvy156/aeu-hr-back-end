<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Department\IndexDepartmentRequest;
use App\Http\Requests\Department\StoreDepartmentRequest;
use App\Http\Requests\Department\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Services\DepartmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class DepartmentController extends Controller
{
    public function __construct(
        protected DepartmentService $departmentService,
    ) {}

    public function index(IndexDepartmentRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Department::class);

        $paginator = $this->departmentService->paginate($request->validated());
        $paginator->through(fn (Department $department) => DepartmentResource::make($department)->resolve($request));

        return ApiResponse::paginated(
            paginator: $paginator,
            data: $paginator->items(),
            message: 'Departments fetched successfully.',
        );
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $this->authorize('create', Department::class);

        $department = $this->departmentService->create($request->validated());
        $department->loadCount(['positions', 'employees']);

        return ApiResponse::success(
            data: DepartmentResource::make($department)->resolve($request),
            message: 'Department created successfully.',
            status: 201,
        );
    }

    public function show(Department $department): JsonResponse
    {
        $this->authorize('view', $department);

        $department->loadCount(['positions', 'employees']);

        return ApiResponse::success(
            data: DepartmentResource::make($department)->resolve(request()),
            message: 'Department fetched successfully.',
        );
    }

    public function update(UpdateDepartmentRequest $request, Department $department): JsonResponse
    {
        $this->authorize('update', $department);

        $department = $this->departmentService->update($department, $request->validated());
        $department->loadCount(['positions', 'employees']);

        return ApiResponse::success(
            data: DepartmentResource::make($department)->resolve($request),
            message: 'Department updated successfully.',
        );
    }

    public function destroy(Department $department): JsonResponse
    {
        $this->authorize('delete', $department);

        $this->departmentService->delete($department);

        return ApiResponse::success(
            data: null,
            message: 'Department deleted successfully.',
        );
    }
}
