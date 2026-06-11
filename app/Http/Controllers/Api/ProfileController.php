<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\ChangePasswordRequest;
use App\Http\Resources\ProfileResource;
use App\Models\User;
use App\Services\ProfileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(
        protected ProfileService $profileService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $profile = $this->profileService->show($user);

        return ApiResponse::success(
            data: (new ProfileResource(
                $profile['user'],
                $profile['roles'],
                $profile['permissions'],
            ))->resolve($request),
            message: 'Profile fetched successfully.',
        );
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $this->profileService->changePassword(
            user: $user,
            currentPassword: $request->validated('current_password'),
            newPassword: $request->validated('password'),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success(message: 'Password changed successfully.');
    }
}
