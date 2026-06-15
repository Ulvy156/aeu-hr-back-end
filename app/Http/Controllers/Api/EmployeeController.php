<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\IndexEmployeeRequest;
use App\Http\Requests\Employee\SearchEmployeeRequest;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeHierarchyNodeResource;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Services\EmployeeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class EmployeeController extends Controller
{
    public function __construct(
        protected EmployeeService $employeeService,
    ) {}

    public function index(IndexEmployeeRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        $paginator = $this->employeeService->paginate($request->validated());
        $paginator->through(fn (Employee $employee) => EmployeeResource::make($employee)->resolve($request));

        return ApiResponse::paginated(
            paginator: $paginator,
            data: $paginator->items(),
            message: 'Employees fetched successfully.',
        );
    }

    public function search(SearchEmployeeRequest $request): JsonResponse
    {
        $this->authorize('search', Employee::class);

        return response()->json(
            $this->employeeService->search($request->validated('q'))->all()
        );
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $this->authorize('create', Employee::class);

        $employee = $this->employeeService->create(
            data: $request->safe()->except('profile_photo'),
            profilePhoto: $request->file('profile_photo'),
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: EmployeeResource::make($employee)->resolve($request),
            message: 'Employee created successfully.',
            status: 201,
        );
    }

    public function show(Employee $employee): JsonResponse
    {
        $this->authorize('view', $employee);

        $employee->loadMissing(['user:id,name,email,status', 'department', 'position', 'manager:id,employee_id,full_name']);

        return ApiResponse::success(
            data: EmployeeResource::make($employee)->resolve(request()),
            message: 'Employee fetched successfully.',
        );
    }

    public function hierarchy(): JsonResponse
    {
        return ApiResponse::success(
            data: EmployeeHierarchyNodeResource::collection($this->employeeService->tree())->resolve(request()),
            message: 'Employee hierarchy fetched successfully.',
        );
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): JsonResponse
    {
        $this->authorize('update', $employee);

        if ($this->isSalaryBeingChanged($employee, $request->validated('base_salary'))) {
            $this->authorize('updateSalary', $employee);
        }

        $employee = $this->employeeService->update(
            employee: $employee,
            data: $request->safe()->except('profile_photo'),
            profilePhoto: $request->file('profile_photo'),
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: EmployeeResource::make($employee)->resolve($request),
            message: 'Employee updated successfully.',
        );
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $this->authorize('delete', $employee);

        $this->employeeService->delete(
            employee: $employee,
            actor: request()->user(),
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
        );

        return ApiResponse::success(
            data: null,
            message: 'Employee deleted successfully.',
        );
    }

    protected function isSalaryBeingChanged(Employee $employee, mixed $newSalary): bool
    {
        return number_format((float) $employee->base_salary, 2, '.', '') !== number_format((float) $newSalary, 2, '.', '');
    }
}
