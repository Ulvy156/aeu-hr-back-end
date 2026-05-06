<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\ClockInRequest;
use App\Http\Requests\Attendance\ClockOutRequest;
use App\Http\Requests\Attendance\CorrectAttendanceRequest;
use App\Http\Requests\Attendance\IndexAttendanceRequest;
use App\Http\Requests\Attendance\IndexAttendanceSummaryRequest;
use App\Http\Requests\Attendance\MarkAbsentRequest;
use App\Http\Requests\Attendance\ProxyClockInRequest;
use App\Http\Requests\Attendance\ProxyClockOutRequest;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Services\AttendanceService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

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
}
