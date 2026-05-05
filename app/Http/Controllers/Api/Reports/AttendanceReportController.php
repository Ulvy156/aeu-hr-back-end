<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\IndexAttendanceReportRequest;
use App\Http\Resources\AttendanceResource;
use App\Services\Reports\AttendanceReportService;
use App\Support\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AttendanceReportController extends Controller
{
    public function __construct(
        protected AttendanceReportService $attendanceReportService,
    ) {}

    public function index(IndexAttendanceReportRequest $request)
    {
        $report = $this->attendanceReportService->report($request->validated());

        if (! $report['paginated']) {
            return ApiResponse::success(
                data: [
                    'report_type' => $report['report_type'],
                    'summary' => $report['summary'],
                    'items' => $report['items'],
                ],
                message: 'Attendance report fetched successfully.',
            );
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $report['paginator'];
        $items = $paginator->items();

        if ($report['resource'] === 'attendance') {
            $items = AttendanceResource::collection(collect($items))->resolve($request);
        } else {
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
            message: 'Attendance report fetched successfully.',
        );
    }

    public function export(IndexAttendanceReportRequest $request): BinaryFileResponse
    {
        $export = $this->attendanceReportService->export($request->validated());

        return Excel::download($export['export'], $export['file_name']);
    }
}
