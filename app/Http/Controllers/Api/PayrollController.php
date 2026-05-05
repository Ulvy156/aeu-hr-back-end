<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\ApprovePayrollRequest;
use App\Http\Requests\Payroll\GeneratePayrollRequest;
use App\Http\Requests\Payroll\IndexPayrollRequest;
use App\Http\Requests\Payroll\RejectPayrollRequest;
use App\Http\Requests\Payroll\SubmitPayrollRequest;
use App\Http\Requests\Payroll\UpdatePayrollRequest;
use App\Http\Resources\PayrollBatchResource;
use App\Models\PayrollBatch;
use App\Services\PayrollService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class PayrollController extends Controller
{
    public function __construct(
        protected PayrollService $payrollService,
    ) {}

    public function index(IndexPayrollRequest $request): JsonResponse
    {
        $this->authorize('viewAny', PayrollBatch::class);

        $paginator = $this->payrollService->paginate(
            filters: $request->validated(),
            viewer: $request->user(),
        );

        $paginator->through(fn (PayrollBatch $payrollBatch) => PayrollBatchResource::make($payrollBatch)->resolve($request));

        return ApiResponse::paginated(
            paginator: $paginator,
            data: $paginator->items(),
            message: 'Payrolls fetched successfully.',
        );
    }

    public function store(GeneratePayrollRequest $request): JsonResponse
    {
        $this->authorize('generate', PayrollBatch::class);

        $payrollBatch = $this->payrollService->generate(
            month: (int) $request->validated('month'),
            year: (int) $request->validated('year'),
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: PayrollBatchResource::make($payrollBatch)->resolve($request),
            message: 'Payroll generated successfully.',
            status: 201,
        );
    }

    public function show(PayrollBatch $payroll): JsonResponse
    {
        $this->authorize('view', $payroll);

        $payroll = $this->payrollService->loadBatchRelations($payroll);

        return ApiResponse::success(
            data: PayrollBatchResource::make($payroll)->resolve(request()),
            message: 'Payroll fetched successfully.',
        );
    }

    public function update(UpdatePayrollRequest $request, PayrollBatch $payroll): JsonResponse
    {
        $this->authorize('update', $payroll);

        $payroll = $this->payrollService->update(
            payrollBatch: $payroll,
            data: $request->validated(),
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: PayrollBatchResource::make($payroll)->resolve($request),
            message: 'Payroll updated successfully.',
        );
    }

    public function submit(SubmitPayrollRequest $request, PayrollBatch $payroll): JsonResponse
    {
        $this->authorize('submit', $payroll);

        $payroll = $this->payrollService->submit(
            payrollBatch: $payroll,
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: PayrollBatchResource::make($payroll)->resolve($request),
            message: 'Payroll submitted successfully.',
        );
    }

    public function approve(ApprovePayrollRequest $request, PayrollBatch $payroll): JsonResponse
    {
        $this->authorize('approve', $payroll);

        $payroll = $this->payrollService->approve(
            payrollBatch: $payroll,
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: PayrollBatchResource::make($payroll)->resolve($request),
            message: 'Payroll approved successfully.',
        );
    }

    public function reject(RejectPayrollRequest $request, PayrollBatch $payroll): JsonResponse
    {
        $this->authorize('reject', $payroll);

        $payroll = $this->payrollService->reject(
            payrollBatch: $payroll,
            actor: $request->user(),
            rejectionReason: (string) $request->validated('rejection_reason'),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: PayrollBatchResource::make($payroll)->resolve($request),
            message: 'Payroll rejected successfully.',
        );
    }
}
