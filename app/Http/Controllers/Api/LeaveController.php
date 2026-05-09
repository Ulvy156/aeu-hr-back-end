<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Leave\ApproveLeaveRequest;
use App\Http\Requests\Leave\IndexLeaveBalanceRequest;
use App\Http\Requests\Leave\IndexLeaveRequest;
use App\Http\Requests\Leave\RejectLeaveRequest;
use App\Http\Requests\Leave\StoreLeaveRequest;
use App\Http\Resources\LeaveBalanceResource;
use App\Http\Resources\LeaveResource;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\LeaveService;
use App\Exceptions\ApiException;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use App\Models\User;

class LeaveController extends Controller
{
    public function __construct(
        protected LeaveService $leaveService,
    ) {}

    public function index(IndexLeaveRequest $request): JsonResponse
    {
        $this->authorize('viewAny', LeaveRequest::class);

        $paginator = $this->leaveService->paginate(
            filters: $request->validated(),
            viewer: $request->user(),
        );

        $paginator->through(fn (LeaveRequest $leave) => LeaveResource::make($leave)->resolve($request));

        return ApiResponse::paginated(
            paginator: $paginator,
            data: $paginator->items(),
            message: 'Leaves fetched successfully.',
        );
    }

    public function store(StoreLeaveRequest $request): JsonResponse
    {
        $this->authorize('create', LeaveRequest::class);

        $leave = $this->leaveService->create(
            data: $request->validated(),
            actor: $request->user(),
        );

        return ApiResponse::success(
            data: LeaveResource::make($leave)->resolve($request),
            message: 'Leave requested successfully.',
            status: 201,
        );
    }

    public function show(LeaveRequest $leave): JsonResponse
    {
        $this->authorize('view', $leave);

        $leave->loadMissing([
            'employee:id,user_id,employee_id,full_name',
            'hrApprovedBy:id,name',
            'ceoApprovedBy:id,name',
        ]);

        return ApiResponse::success(
            data: LeaveResource::make($leave)->resolve(request()),
            message: 'Leave fetched successfully.',
        );
    }

    public function balances(IndexLeaveBalanceRequest $request): JsonResponse
    {
        $employee = $this->resolveBalanceEmployee($request->user(), $request->validated());
        $year = (int) ($request->validated('year') ?? now()->year);

        $result = $this->leaveService->balances(
            employee: $employee,
            year: $year,
        );

        return ApiResponse::success(
            data: [
                'employee' => $result['employee'],
                'year' => $result['year'],
                'balances' => LeaveBalanceResource::collection(collect($result['balances']))->resolve($request),
            ],
            message: 'Leave balances fetched successfully.',
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function resolveBalanceEmployee(User $user, array $filters): Employee
    {
        $employeeId = $filters['employee_id'] ?? null;
        $canViewAny = $user->can('viewBalanceAny', LeaveRequest::class);
        $canViewOwn = $user->can('viewBalanceOwn', LeaveRequest::class);

        if ($canViewAny) {
            if ($employeeId !== null) {
                return Employee::query()->findOrFail((int) $employeeId);
            }

            if ($canViewOwn) {
                return $this->employeeForUserOrFail($user);
            }

            throw ApiException::badRequest('The employee_id field is required for this account.');
        }

        if (! $canViewOwn) {
            throw ApiException::forbidden();
        }

        $employee = $this->employeeForUserOrFail($user);

        if ($employeeId !== null && (int) $employeeId !== $employee->id) {
            throw ApiException::forbidden();
        }

        return $employee;
    }

    protected function employeeForUserOrFail(User $user): Employee
    {
        $employee = $user->loadMissing('employee')->employee;

        if (! $employee) {
            throw ApiException::forbidden('No employee profile is linked to this user account.');
        }

        return $employee;
    }

    public function approve(ApproveLeaveRequest $request, LeaveRequest $leave): JsonResponse
    {
        $this->authorize('approve', $leave);

        $leave = $this->leaveService->approve(
            leave: $leave,
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: LeaveResource::make($leave)->resolve($request),
            message: 'Leave approved successfully.',
        );
    }

    public function reject(RejectLeaveRequest $request, LeaveRequest $leave): JsonResponse
    {
        $this->authorize('reject', $leave);

        $leave = $this->leaveService->reject(
            leave: $leave,
            actor: $request->user(),
            rejectionReason: $request->validated('rejection_reason'),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: LeaveResource::make($leave)->resolve($request),
            message: 'Leave rejected successfully.',
        );
    }

    public function cancel(LeaveRequest $leave): JsonResponse
    {
        $this->authorize('cancel', $leave);

        $leave = $this->leaveService->cancel(
            leave: $leave,
            actor: request()->user(),
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
        );

        return ApiResponse::success(
            data: LeaveResource::make($leave)->resolve(request()),
            message: 'Leave cancelled successfully.',
        );
    }
}
