<?php

namespace App\Console\Commands;

use App\Models\EmployeeUpgradeRequest;
use App\Services\EmployeeUpgradeRequestService;
use Illuminate\Console\Command;

class ApplyDuePromotionsCommand extends Command
{
    protected $signature = 'employees:apply-due-promotions';

    protected $description = 'Apply CEO-approved promotions whose effective month has arrived';

    public function handle(EmployeeUpgradeRequestService $service): int
    {
        $count = $service->applyDue();
        $this->info("Applied {$count} promotion(s).");
        $failed = EmployeeUpgradeRequest::query()->where('status', 'approved')->whereNull('applied_at')
            ->whereDate('effective_date', '<=', now('Asia/Phnom_Penh')->toDateString())->whereNotNull('application_failed_at')->count();
        if ($failed) {
            $this->error("{$failed} due promotion(s) failed. See request details and server logs.");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
