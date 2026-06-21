<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\IndexLeaveReportRequest;
use App\Http\Resources\LeaveResource;
use App\Services\Reports\LeaveReportService;
use App\Support\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Arrayable;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LeaveReportController extends Controller
{
    public function __construct(
        protected LeaveReportService $leaveReportService,
    ) {}

    public function index(IndexLeaveReportRequest $request)
    {
        $report = $this->leaveReportService->report($request->validated());

        if (! $report['paginated']) {
            return ApiResponse::success(
                data: [
                    'report_type' => $report['report_type'],
                    'summary' => $report['summary'],
                    'items' => $report['items'],
                ],
                message: 'Leave report fetched successfully.',
            );
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $report['paginator'];
        $items = $paginator->items();

        if ($report['resource'] === 'leave') {
            $items = LeaveResource::collection(collect($items))->resolve($request);
        } else {
            $items = collect($items)
                ->map(fn (mixed $item): mixed => $item instanceof Arrayable ? $item->toArray() : $item)
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
            message: 'Leave report fetched successfully.',
        );
    }

    public function export(IndexLeaveReportRequest $request): BinaryFileResponse
    {
        $export = $this->leaveReportService->export($request->validated());

        return Excel::download($export['export'], $export['file_name']);
    }
}
