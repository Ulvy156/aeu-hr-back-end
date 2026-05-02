<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Position\IndexPositionRequest;
use App\Http\Requests\Position\StorePositionRequest;
use App\Http\Requests\Position\UpdatePositionRequest;
use App\Http\Resources\PositionResource;
use App\Models\Position;
use App\Services\PositionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class PositionController extends Controller
{
    public function __construct(
        protected PositionService $positionService,
    ) {}

    public function index(IndexPositionRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Position::class);

        $paginator = $this->positionService->paginate($request->validated());
        $paginator->through(fn (Position $position) => PositionResource::make($position)->resolve($request));

        return ApiResponse::paginated(
            paginator: $paginator,
            data: $paginator->items(),
            message: 'Positions fetched successfully.',
        );
    }

    public function store(StorePositionRequest $request): JsonResponse
    {
        $this->authorize('create', Position::class);

        $position = $this->positionService->create($request->validated());
        $position->loadCount('employees');

        return ApiResponse::success(
            data: PositionResource::make($position)->resolve($request),
            message: 'Position created successfully.',
            status: 201,
        );
    }

    public function show(Position $position): JsonResponse
    {
        $this->authorize('view', $position);

        $position->load(['department'])->loadCount('employees');

        return ApiResponse::success(
            data: PositionResource::make($position)->resolve(request()),
            message: 'Position fetched successfully.',
        );
    }

    public function update(UpdatePositionRequest $request, Position $position): JsonResponse
    {
        $this->authorize('update', $position);

        $position = $this->positionService->update($position, $request->validated());
        $position->loadCount('employees');

        return ApiResponse::success(
            data: PositionResource::make($position)->resolve($request),
            message: 'Position updated successfully.',
        );
    }

    public function destroy(Position $position): JsonResponse
    {
        $this->authorize('delete', $position);

        $this->positionService->delete($position);

        return ApiResponse::success(
            data: null,
            message: 'Position deleted successfully.',
        );
    }
}
