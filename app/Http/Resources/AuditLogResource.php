<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Activitylog\Models\Activity;

/**
 * @mixin Activity
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => $this->whenLoaded('causer', fn () => [
                'id' => $this->causer?->id,
                'name' => $this->causer?->name,
                'email' => $this->causer?->email,
            ]),
            'action' => $this->description,
            'module' => $this->log_name,
            'model_type' => $this->subject_type,
            'model_id' => $this->subject_id,
            'old_values' => $this->getExtraProperty('old_values'),
            'new_values' => $this->getExtraProperty('new_values'),
            'ip_address' => $this->getExtraProperty('ip_address'),
            'user_agent' => $this->getExtraProperty('user_agent'),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
