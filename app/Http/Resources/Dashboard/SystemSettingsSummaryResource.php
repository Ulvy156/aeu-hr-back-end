<?php

namespace App\Http\Resources\Dashboard;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SystemSettingsSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'company_name' => $this['company_name'],
            'salary_currency' => $this['salary_currency'],
            'payroll_day_rate' => $this['payroll_day_rate'],
            'allowed_radius_meters' => $this['allowed_radius_meters'],
            'working_start_time' => $this['working_start_time'],
            'working_end_time' => $this['working_end_time'],
            'working_days' => $this['working_days'],
            'working_days_count' => $this['working_days_count'],
            'office_location_configured' => $this['office_location_configured'],
        ];
    }
}
