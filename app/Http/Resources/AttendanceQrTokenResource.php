<?php

namespace App\Http\Resources;

use App\Models\AttendanceQrToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AttendanceQrToken
 */
class AttendanceQrTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'token' => $this->token,
            'scan_url' => $this->scan_url,
            'generated_by' => $this->whenLoaded('generatedBy', fn () => $this->generatedBy
                ? [
                    'id' => $this->generatedBy->id,
                    'name' => $this->generatedBy->name,
                    'email' => $this->generatedBy->email,
                ]
                : null),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
