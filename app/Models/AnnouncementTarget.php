<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['announcement_id', 'target_type', 'target_id'])]
class AnnouncementTarget extends Model
{
    use HasFactory;

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }
}
