<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AuditLog\IndexAuditLogRequest;
use App\Http\Resources\AuditLogResource;
use App\Services\AuditLogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Spatie\Activitylog\Models\Activity;

class AuditLogController extends Controller
{
    public function __construct(
        protected AuditLogService $auditLogService,
    ) {}

    public function index(IndexAuditLogRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Activity::class);

        $paginator = $this->auditLogService->paginate($request->validated());
        $paginator->through(fn (Activity $activity) => AuditLogResource::make($activity)->resolve($request));

        return ApiResponse::paginated(
            paginator: $paginator,
            data: $paginator->items(),
            message: 'Audit logs fetched successfully.',
        );
    }
}
