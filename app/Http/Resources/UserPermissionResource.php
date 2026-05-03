<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin array{user_id: int, direct_permissions: array<int, string>, role_permissions: array<int, string>, all_permissions: array<int, string>}
 */
class UserPermissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->resource['user_id'],
            'direct_permissions' => $this->resource['direct_permissions'],
            'role_permissions' => $this->resource['role_permissions'],
            'all_permissions' => $this->resource['all_permissions'],
        ];
    }
}
