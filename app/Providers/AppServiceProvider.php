<?php

namespace App\Providers;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Policies\AuditLogPolicy;
use App\Policies\DepartmentPolicy;
use App\Policies\EmployeePolicy;
use App\Policies\PositionPolicy;
use App\Policies\UserPolicy;
use App\Support\ApiResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Activity::class, AuditLogPolicy::class);
        Gate::policy(Department::class, DepartmentPolicy::class);
        Gate::policy(Position::class, PositionPolicy::class);
        Gate::policy(Employee::class, EmployeePolicy::class);
        Gate::policy(User::class, UserPolicy::class);

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)
                ->by(Str::lower((string) $request->input('email')).'|'.$request->ip())
                ->response(function (Request $request, array $headers) {
                    return ApiResponse::error(
                        message: 'Too many login attempts. Please try again later.',
                        errors: [
                            'email' => ['Too many login attempts. Please try again later.'],
                        ],
                        status: 429,
                        headers: $headers,
                    );
                });
        });
    }
}
