<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\IndexMonthlyLeaveReportRequest;
use App\Services\Reports\MonthlyLeaveReportService;
use App\Support\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Arrayable;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class MonthlyLeaveReportController extends Controller
{
    public function __construct(
        protected MonthlyLeaveReportService $monthlyLeaveReportService,
    ) {}

    public function index(IndexMonthlyLeaveReportRequest $request)
    {
        $report = $this->monthlyLeaveReportService->report($request->validated());

        /** @var LengthAwarePaginator $paginator */
        $paginator = $report['paginator'];
        $items = collect($paginator->items())
            ->map(fn (mixed $item): mixed => $item instanceof Arrayable ? $item->toArray() : $item)
            ->values()
            ->all();

        return ApiResponse::paginated(
            paginator: $paginator,
            data: [
                'report_type' => $report['report_type'],
                'summary' => $report['summary'],
                'items' => $items,
            ],
            message: 'Monthly leave report fetched successfully.',
        );
    }

    public function export(IndexMonthlyLeaveReportRequest $request): BinaryFileResponse
    {
        $export = $this->monthlyLeaveReportService->export($request->validated());

        return Excel::download($export['export'], $export['file_name']);
    }

    public function exportPdf(IndexMonthlyLeaveReportRequest $request): Response
    {
        return $this->monthlyLeaveReportService->pdf($request->validated());
    }
}
