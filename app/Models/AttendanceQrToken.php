<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'token',
    'generated_by',
])]
class AttendanceQrToken extends Model
{
    use HasFactory;

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function getScanUrlAttribute(): string
    {
        $base = config('app.frontend_url') ?: config('app.url');

        return rtrim((string) $base, '/').'/attendance/scan?token='.$this->token;
    }
}
