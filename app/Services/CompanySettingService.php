<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CompanySettingService
{
    public function __construct(
        protected AuditLogService $auditLogService,
    ) {}

    /**
     * Return the singleton company settings row, creating defaults if needed.
     */
    public function current(): CompanySetting
    {
        return DB::transaction(function (): CompanySetting {
            $primary = CompanySetting::query()
                ->orderBy('id')
                ->first();

            if (! $primary) {
                return CompanySetting::query()->create($this->defaultAttributes());
            }

            $this->deleteDuplicateRows($primary);

            return $primary;
        });
    }

    /**
     * Ensure the singleton settings row exists with defaults.
     */
    public function ensureDefaultExists(): CompanySetting
    {
        return $this->current();
    }

    /**
     * Update the singleton company settings row.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(
        array $attributes,
        ?UploadedFile $companyLogo = null,
        ?User $actor = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): CompanySetting {
        return DB::transaction(function () use ($attributes, $companyLogo, $actor, $ipAddress, $userAgent): CompanySetting {
            $setting = $this->current();
            $oldValues = $this->auditAttributes($setting);

            if ($companyLogo) {
                if ($setting->company_logo) {
                    Storage::disk('public')->delete($setting->company_logo);
                }

                $attributes['company_logo'] = $companyLogo->store('company-logos', 'public');
            }

            $setting->fill($attributes);
            $setting->save();

            $this->deleteDuplicateRows($setting);

            $setting = $setting->refresh();

            if ($actor) {
                $this->auditLogService->log(
                    action: 'update',
                    module: 'company_settings',
                    user: $actor,
                    subject: $setting,
                    oldValues: $oldValues,
                    newValues: $this->auditAttributes($setting),
                    ipAddress: $ipAddress,
                    userAgent: $userAgent,
                );
            }

            return $setting;
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultAttributes(): array
    {
        return (array) config('hr.company_settings', []);
    }

    protected function deleteDuplicateRows(CompanySetting $primary): void
    {
        CompanySetting::query()
            ->whereKeyNot($primary->getKey())
            ->delete();
    }

    /**
     * @return array<string, mixed>
     */
    protected function auditAttributes(CompanySetting $setting): array
    {
        return [
            'company_name' => $setting->company_name,
            'company_logo' => $setting->company_logo,
            'company_address' => $setting->company_address,
            'company_phone' => $setting->company_phone,
            'company_email' => $setting->company_email,
            'office_latitude' => $setting->office_latitude,
            'office_longitude' => $setting->office_longitude,
            'allowed_radius_meters' => $setting->allowed_radius_meters,
            'working_start_time' => $setting->working_start_time,
            'working_end_time' => $setting->working_end_time,
            'working_days' => $setting->working_days,
            'salary_currency' => $setting->salary_currency,
            'payroll_day_rate' => $setting->payroll_day_rate,
        ];
    }
}
