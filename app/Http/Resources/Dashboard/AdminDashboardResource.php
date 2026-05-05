<?php

namespace App\Http\Resources\Dashboard;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminDashboardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user_count' => $this['user_count'],
            'user_summary' => $this['user_summary'],
            'system_settings_summary' => SystemSettingsSummaryResource::make($this['system_settings_summary'])->resolve($request),
        ];
    }
}
