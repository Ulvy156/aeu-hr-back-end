<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\IndexPayrollReportRequest;
use App\Http\Resources\PayrollBatchResource;
use App\Http\Resources\PayrollItemResource;
use App\Services\Reports\PayrollReportService;
use App\Support\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PayrollReportController extends Controller
{
    public function __construct(
        protected PayrollReportService $payrollReportService,
    ) {}

    public function index(IndexPayrollReportRequest $request)
    {
        $report = $this->payrollReportService->report($request->validated());

        if (! $report['paginated']) {
            return ApiResponse::success(
                data: [
                    'report_type' => $report['report_type'],
                    'summary' => $report['summary'],
                    'items' => $report['items'],
                ],
                message: 'Payroll report fetched successfully.',
            );
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $report['paginator'];
        $items = $paginator->items();

        if ($report['resource'] === 'payroll_batch') {
            $items = PayrollBatchResource::collection(collect($items))->resolve($request);
        }

        if ($report['resource'] === 'payroll_item') {
            $items = PayrollItemResource::collection(collect($items))->resolve($request);
        }

        if (! in_array($report['resource'], ['payroll_batch', 'payroll_item'], true)) {
            $items = collect($items)
                ->map(fn (mixed $item): mixed => $item instanceof \Illuminate\Contracts\Support\Arrayable ? $item->toArray() : $item)
                ->values()
                ->all();
        }

        return ApiResponse::paginated(
            paginator: $paginator,
            data: [
                'report_type' => $report['report_type'],
                'summary' => $report['summary'],
                'items' => $items,
            ],
            message: 'Payroll report fetched successfully.',
        );
    }

    public function export(IndexPayrollReportRequest $request): BinaryFileResponse
    {
        $export = $this->payrollReportService->export($request->validated());

        return Excel::download($export['export'], $export['file_name']);
    }
}
