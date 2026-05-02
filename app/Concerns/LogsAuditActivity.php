<?php

namespace App\Concerns;

use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;

trait LogsAuditActivity
{
    protected function audit(
        string $action,
        string $module,
        ?User $user = null,
        Model|string|null $subject = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): void {
        app(AuditLogService::class)->log(
            action: $action,
            module: $module,
            user: $user,
            subject: $subject,
            oldValues: $oldValues,
            newValues: $newValues,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
        );
    }
}
