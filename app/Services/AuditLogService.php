<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Contracts\Activity;
use Spatie\Activitylog\Models\Activity as ActivityModel;

class AuditLogService
{
    public function log(
        string $action,
        string $module,
        ?User $user = null,
        Model|string|null $subject = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Activity {
        $logger = activity($module)
            ->event($action)
            ->withProperties([
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
            ]);

        if ($user) {
            $logger->causedBy($user);
        }

        if ($subject instanceof Model) {
            $logger->performedOn($subject);
        }

        return $logger->log($action);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 15);

        return ActivityModel::query()
            ->with('causer')
            ->when($filters['user_id'] ?? null, function ($query, $userId) {
                $query
                    ->where('causer_type', (new User)->getMorphClass())
                    ->where('causer_id', $userId);
            })
            ->when($filters['module'] ?? null, fn ($query, $module) => $query->where('log_name', $module))
            ->when($filters['action'] ?? null, fn ($query, $action) => $query->where('description', $action))
            ->when($filters['date_from'] ?? null, fn ($query, $dateFrom) => $query->whereDate('created_at', '>=', $dateFrom))
            ->when($filters['date_to'] ?? null, fn ($query, $dateTo) => $query->whereDate('created_at', '<=', $dateTo))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }
}
