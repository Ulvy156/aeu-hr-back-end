<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\ClockInRequest;
use App\Http\Requests\Attendance\ClockOutRequest;
use App\Http\Requests\Attendance\CorrectAttendanceRequest;
use App\Http\Requests\Attendance\GenerateQrRequest;
use App\Http\Requests\Attendance\IndexAttendanceRequest;
use App\Http\Requests\Attendance\IndexAttendanceSummaryRequest;
use App\Http\Requests\Attendance\MarkAbsentRequest;
use App\Http\Requests\Attendance\ProxyClockInRequest;
use App\Http\Requests\Attendance\ProxyClockOutRequest;
use App\Http\Requests\Attendance\ScanQrRequest;
use App\Http\Resources\AttendanceQrTokenResource;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Models\AttendanceQrToken;
use App\Services\AttendanceService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class AttendanceController extends Controller
{
    public function __construct(
        protected AttendanceService $attendanceService,
    ) {}

    public function index(IndexAttendanceRequest $request): JsonResponse
    {
        $paginator = $this->attendanceService->paginate(
            filters: $request->validated(),
            viewer: $request->user(),
        );

        $paginator->through(fn (Attendance $attendance) => AttendanceResource::make($attendance)->resolve($request));

        return ApiResponse::paginated(
            paginator: $paginator,
            data: $paginator->items(),
            message: 'Attendance fetched successfully.',
        );
    }

    public function clockIn(ClockInRequest $request): JsonResponse
    {
        $this->authorize('clockIn', Attendance::class);

        $attendance = $this->attendanceService->clockIn(
            user: $request->user(),
            latitude: (float) $request->validated('latitude'),
            longitude: (float) $request->validated('longitude'),
        );

        return ApiResponse::success(
            data: AttendanceResource::make($attendance)->resolve($request),
            message: 'Clock-in successful.',
            status: 201,
        );
    }

    public function clockOut(ClockOutRequest $request): JsonResponse
    {
        $this->authorize('clockOut', Attendance::class);

        $attendance = $this->attendanceService->clockOut(
            user: $request->user(),
            latitude: (float) $request->validated('latitude'),
            longitude: (float) $request->validated('longitude'),
        );

        return ApiResponse::success(
            data: AttendanceResource::make($attendance)->resolve($request),
            message: 'Clock-out successful.',
        );
    }

    public function correct(CorrectAttendanceRequest $request, Attendance $attendance): JsonResponse
    {
        $this->authorize('correct', $attendance);

        $attendance = $this->attendanceService->correct(
            attendance: $attendance,
            data: $request->validated(),
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: AttendanceResource::make($attendance)->resolve($request),
            message: 'Attendance corrected successfully.',
        );
    }

    public function summary(IndexAttendanceSummaryRequest $request): JsonResponse
    {
        $this->authorize('viewOwn', Attendance::class);

        $result = $this->attendanceService->summary(
            viewer: $request->user(),
            filters: $request->validated(),
        );

        return ApiResponse::success(
            data: $result,
            message: 'Attendance summary fetched successfully.',
        );
    }

    public function proxyClockIn(ProxyClockInRequest $request): JsonResponse
    {
        $this->authorize('proxyClock', Attendance::class);

        $attendance = $this->attendanceService->proxyClockIn(
            actor: $request->user(),
            employeeId: (int) $request->validated('employee_id'),
            attendanceDate: (string) $request->validated('attendance_date'),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: AttendanceResource::make($attendance)->resolve($request),
            message: 'Proxy clock-in recorded successfully.',
            status: 201,
        );
    }

    public function proxyClockOut(ProxyClockOutRequest $request): JsonResponse
    {
        $this->authorize('proxyClock', Attendance::class);

        $attendance = $this->attendanceService->proxyClockOut(
            actor: $request->user(),
            employeeId: (int) $request->validated('employee_id'),
            attendanceDate: (string) $request->validated('attendance_date'),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: AttendanceResource::make($attendance)->resolve($request),
            message: 'Proxy clock-out recorded successfully.',
        );
    }

    public function markAbsent(MarkAbsentRequest $request): JsonResponse
    {
        $this->authorize('markAbsent', Attendance::class);

        $result = $this->attendanceService->markAbsent(
            attendanceDate: $request->validated('attendance_date'),
        );

        return ApiResponse::success(
            data: $result,
            message: 'Absent marking completed successfully.',
        );
    }

    public function generateQr(GenerateQrRequest $request): JsonResponse
    {
        $this->authorize('generateQr', Attendance::class);

        $qrToken = $this->attendanceService->generateQrToken(
            actor: $request->user(),
        );

        return ApiResponse::success(
            data: AttendanceQrTokenResource::make($qrToken)->resolve($request),
            message: 'QR token ready.',
            status: 201,
        );
    }

    public function currentQr(): JsonResponse
    {
        $this->authorize('generateQr', Attendance::class);

        $qrToken = $this->attendanceService->currentQrToken();

        return ApiResponse::success(
            data: $qrToken ? AttendanceQrTokenResource::make($qrToken)->resolve() : null,
            message: $qrToken ? 'Active QR token fetched.' : 'No active QR token.',
        );
    }

    public function downloadQr(AttendanceQrToken $qrToken): Response
    {
        $this->authorize('generateQr', Attendance::class);

        return $this->attendanceService->downloadQrImage($qrToken);
    }

    public function destroyQr(AttendanceQrToken $qrToken): JsonResponse
    {
        $this->authorize('generateQr', Attendance::class);

        $this->attendanceService->deleteQrToken(
            qrToken: $qrToken,
            actor: request()->user(),
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
        );

        return ApiResponse::success(
            data: null,
            message: 'QR token deleted successfully.',
        );
    }

    public function scanQr(ScanQrRequest $request): JsonResponse
    {
        $this->authorize('clockIn', Attendance::class);

        $result = $this->attendanceService->scanQr(
            user: $request->user(),
            token: (string) $request->validated('token'),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(
            data: [
                'action' => $result['action'],
                'attendance' => AttendanceResource::make($result['attendance'])->resolve($request),
            ],
            message: $result['action'] === 'qr_clock_in' ? 'QR clock-in successful.' : 'QR clock-out successful.',
            status: $result['action'] === 'qr_clock_in' ? 201 : 200,
        );
    }
}
