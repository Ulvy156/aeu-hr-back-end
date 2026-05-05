<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\IndexPayslipRequest;
use App\Http\Resources\PayslipResource;
use App\Models\PayrollItem;
use App\Services\PayslipService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class PayslipController extends Controller
{
    public function __construct(
        protected PayslipService $payslipService,
    ) {}

    public function index(IndexPayslipRequest $request): JsonResponse
    {
        $this->authorize('viewAny', PayrollItem::class);

        $paginator = $this->payslipService->paginate(
            filters: $request->validated(),
            viewer: $request->user(),
        );

        $paginator->through(fn (PayrollItem $payslip) => PayslipResource::make($payslip)->resolve($request));

        return ApiResponse::paginated(
            paginator: $paginator,
            data: $paginator->items(),
            message: 'Payslips fetched successfully.',
        );
    }

    public function show(PayrollItem $payslip): JsonResponse
    {
        $this->authorize('view', $payslip->loadMissing('payrollBatch'));

        $payslip = $this->payslipService->loadPayslip($payslip);

        return ApiResponse::success(
            data: PayslipResource::make($payslip)->resolve(request()),
            message: 'Payslip fetched successfully.',
        );
    }

    public function download(PayrollItem $payslip)
    {
        $this->authorize('download', $payslip->loadMissing('payrollBatch'));

        return $this->payslipService->download($payslip);
    }
}
