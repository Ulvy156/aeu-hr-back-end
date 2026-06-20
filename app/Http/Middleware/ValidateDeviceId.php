<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use App\Support\DeviceFingerprint;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateDeviceId
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $token = $user->currentAccessToken();

        if (! $token || ! $token->device_id) {
            return $next($request);
        }

        $fingerprint = DeviceFingerprint::generate($request);

        if ($token->device_id !== $fingerprint) {
            $token->delete();

            return ApiResponse::error('Device mismatch. Please log in again.', status: 401);
        }

        return $next($request);
    }
}
