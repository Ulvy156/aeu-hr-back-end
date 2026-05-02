<?php

namespace App\Services;

use App\Models\CompanySetting;
use Illuminate\Support\Facades\DB;

class CompanySettingService
{
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
    public function update(array $attributes): CompanySetting
    {
        return DB::transaction(function () use ($attributes): CompanySetting {
            $setting = $this->current();

            $setting->fill($attributes);
            $setting->save();

            $this->deleteDuplicateRows($setting);

            return $setting->refresh();
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
}
