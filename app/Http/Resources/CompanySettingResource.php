<?php

namespace App\Http\Resources;

use App\Models\CompanySetting;
use App\Support\FileStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CompanySetting
 */
class CompanySettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_name' => $this->company_name,
            'company_logo' => $this->company_logo,
            'company_logo_url' => FileStorage::url($this->company_logo),
            'company_address' => $this->company_address,
            'company_phone' => $this->company_phone,
            'company_email' => $this->company_email,
            'office_latitude' => $this->office_latitude,
            'office_longitude' => $this->office_longitude,
            'allowed_radius_meters' => $this->allowed_radius_meters,
            'working_start_time' => $this->working_start_time,
            'working_end_time' => $this->working_end_time,
            'working_days' => $this->working_days,
            'salary_currency' => $this->salary_currency,
            'payroll_day_rate' => $this->payroll_day_rate,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
