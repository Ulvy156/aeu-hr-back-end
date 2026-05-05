<?php

namespace App\Services\Dashboard;

use App\Services\CompanySettingService;

class AdminDashboardService
{
    public function __construct(
        protected CompanySettingService $companySettingService,
        protected UserSummaryService $userSummaryService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $userSummary = $this->userSummaryService->summary();
        $settings = $this->companySettingService->current();
        $workingDays = collect($settings->working_days ?? [])
            ->map(fn (mixed $day): string => strtolower((string) $day))
            ->values()
            ->all();

        return [
            'user_count' => $userSummary['total_users'],
            'user_summary' => $userSummary,
            'system_settings_summary' => [
                'company_name' => $settings->company_name,
                'salary_currency' => $settings->salary_currency,
                'payroll_day_rate' => $settings->payroll_day_rate,
                'allowed_radius_meters' => $settings->allowed_radius_meters,
                'working_start_time' => $settings->working_start_time,
                'working_end_time' => $settings->working_end_time,
                'working_days' => $workingDays,
                'working_days_count' => count($workingDays),
                'office_location_configured' => $settings->office_latitude !== null && $settings->office_longitude !== null,
            ],
        ];
    }
}
