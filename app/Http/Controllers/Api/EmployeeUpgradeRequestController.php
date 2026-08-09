<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmployeeUpgradeRequest\ApproveEmployeeUpgradeRequestRequest;
use App\Http\Requests\EmployeeUpgradeRequest\IndexEmployeeUpgradeRequestRequest;
use App\Http\Requests\EmployeeUpgradeRequest\RejectEmployeeUpgradeRequestRequest;
use App\Http\Requests\EmployeeUpgradeRequest\StoreEmployeeUpgradeRequestRequest;
use App\Http\Resources\EmployeeUpgradeRequestResource;
use App\Models\EmployeeUpgradeRequest;
use App\Services\EmployeeUpgradeRequestService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class EmployeeUpgradeRequestController extends Controller
{
    public function __construct(
        protected EmployeeUpgradeRequestService $employeeUpgradeRequestService,
    ) {}

    public function index(IndexEmployeeUpgradeRequestRequest $request): JsonResponse
    {
        $this->authorize('viewAny', EmployeeUpgradeRequest::class);

        $paginator = $this->employeeUpgradeRequestService->paginate(
            filters: $request->validated(),
            viewer: $request->user(),
        );

        $paginator->through(fn (EmployeeUpgradeRequest $upgradeRequest) => EmployeeUpgradeRequestResource::make($upgradeRequest)->resolve($request));

        return ApiResponse::paginated(
            paginator: $paginator,
            data: $paginator->items(),
            message: 'Employee upgrade requests fetched successfully.',
        );
    }

    public function store(StoreEmployeeUpgradeRequestRequest $request): JsonResponse
    {
        $this->authorize('create', EmployeeUpgradeRequest::class);

        $upgradeRequest = $this->employeeUpgradeRequestService->create(
            data: $request->validated(),
            attachmentFiles: $request->file('attachments', []),
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: EmployeeUpgradeRequestResource::make($upgradeRequest)->resolve($request),
            message: 'Employee upgrade request submitted successfully.',
            status: 201,
        );
    }

    public function show(EmployeeUpgradeRequest $upgradeRequest): JsonResponse
    {
        $this->authorize('view', $upgradeRequest);

        $upgradeRequest->loadMissing([
            'employee:id,user_id,employee_id,full_name',
            'requestedBy:id,name',
            'reviewedBy:id,name',
        ]);
        \Log::info($upgradeRequest);

        return ApiResponse::success(
            data: EmployeeUpgradeRequestResource::make($upgradeRequest)->resolve(request()),
            message: 'Employee upgrade request fetched successfully.',
        );
    }

    public function approve(ApproveEmployeeUpgradeRequestRequest $request, EmployeeUpgradeRequest $upgradeRequest): JsonResponse
    {
        $this->authorize('approve', $upgradeRequest);

        $upgradeRequest = $this->employeeUpgradeRequestService->approve(
            upgradeRequest: $upgradeRequest,
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: EmployeeUpgradeRequestResource::make($upgradeRequest)->resolve($request),
            message: 'Employee upgrade request approved successfully.',
        );
    }

    public function reject(RejectEmployeeUpgradeRequestRequest $request, EmployeeUpgradeRequest $upgradeRequest): JsonResponse
    {
        $this->authorize('reject', $upgradeRequest);

        $upgradeRequest = $this->employeeUpgradeRequestService->reject(
            upgradeRequest: $upgradeRequest,
            actor: $request->user(),
            rejectionReason: $request->validated('rejection_reason'),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: EmployeeUpgradeRequestResource::make($upgradeRequest)->resolve($request),
            message: 'Employee upgrade request rejected successfully.',
        );
    }

    public function cancel(EmployeeUpgradeRequest $upgradeRequest): JsonResponse
    {
        $this->authorize('cancel', $upgradeRequest);

        $upgradeRequest = $this->employeeUpgradeRequestService->cancel(
            upgradeRequest: $upgradeRequest,
            actor: request()->user(),
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
        );

        return ApiResponse::success(
            data: EmployeeUpgradeRequestResource::make($upgradeRequest)->resolve(request()),
            message: 'Employee upgrade request cancelled successfully.',
        );
    }
}
