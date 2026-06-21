<?php

use App\Exceptions\ApiException;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        $middleware->encryptCookies(except: ['refresh_token']);
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $exception): bool {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->render(function (ValidationException $exception) {
            return ApiResponse::error(
                message: 'Validation failed',
                errors: $exception->errors(),
                status: $exception->status,
            );
        });

        $exceptions->render(function (HttpResponseException $exception) {
            return $exception->getResponse();
        });

        $exceptions->render(function (ApiException $exception) {
            return ApiResponse::error(
                message: $exception->getMessage(),
                errors: $exception->errors(),
                status: $exception->status(),
                headers: $exception->headers(),
            );
        });

        $exceptions->render(function (AuthenticationException $exception) {
            return ApiResponse::error(
                message: 'Unauthenticated.',
                status: 401,
            );
        });

        $exceptions->render(function (AuthorizationException $exception) {
            $status = $exception->status() ?? 403;

            return ApiResponse::error(
                message: $status === 404 ? 'Resource not found.' : 'Forbidden.',
                status: $status,
            );
        });

        $exceptions->render(function (HttpExceptionInterface $exception) {
            $status = $exception->getStatusCode();

            return ApiResponse::error(
                message: match ($status) {
                    400 => 'Bad request.',
                    401 => 'Unauthenticated.',
                    403 => 'Forbidden.',
                    404 => 'Resource not found.',
                    405 => 'Method not allowed.',
                    429 => 'Too many requests.',
                    default => $status >= 500 ? 'Server error.' : ($exception->getMessage() ?: 'Something went wrong.'),
                },
                status: $status,
                headers: $exception->getHeaders(),
            );
        });

        $exceptions->render(function (Throwable $exception) {
            return ApiResponse::error(
                message: 'Server error.',
                status: 500,
            );
        });
    })->create();
