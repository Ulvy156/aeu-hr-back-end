<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\BackupService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends Controller
{
    public function __construct(
        protected BackupService $backupService,
        protected AuditLogService $auditLogService,
    ) {}

    public function download(Request $request): BinaryFileResponse
    {
        $this->authorize('downloadBackup');

        $path = $this->backupService->generateDatabaseDump();

        $this->auditLogService->log(
            action: 'backup_downloaded',
            module: 'backups',
            user: $request->user(),
            newValues: ['filename' => basename($path)],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()
            ->download($path, basename($path), ['Content-Type' => 'application/sql'])
            ->deleteFileAfterSend();
    }
}
