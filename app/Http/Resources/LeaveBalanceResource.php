<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveBalanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'leave_type' => $this['leave_type'],
            'entitlement' => $this['entitlement'],
            'used' => $this['used'],
            'remaining' => $this['remaining'],
            'is_unlimited' => $this['is_unlimited'],
            'rule' => $this['rule'],
        ];
    }
}
