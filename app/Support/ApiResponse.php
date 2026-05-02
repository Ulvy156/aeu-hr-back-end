<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

class ApiResponse
{
    /**
     * Build a successful JSON response.
     */
    public static function success(
        mixed $data = null,
        string $message = 'Action completed successfully',
        int $status = 200,
        array $headers = [],
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status, $headers);
    }

    /**
     * Build an error JSON response.
     *
     * @param  array<string, mixed>  $errors
     */
    public static function error(
        string $message = 'Something went wrong',
        array $errors = [],
        int $status = 400,
        array $headers = [],
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $status, $headers);
    }

    /**
     * Build a paginated JSON response.
     */
    public static function paginated(
        LengthAwarePaginator $paginator,
        mixed $data = null,
        string $message = 'Data fetched successfully',
        int $status = 200,
        array $headers = [],
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data ?? $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ], $status, $headers);
    }
}
