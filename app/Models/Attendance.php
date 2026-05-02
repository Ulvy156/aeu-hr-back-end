<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'attendance_date',
    'clock_in_time',
    'clock_out_time',
    'clock_in_latitude',
    'clock_in_longitude',
    'clock_out_latitude',
    'clock_out_longitude',
    'status',
    'is_late',
    'correction_reason',
    'corrected_by',
    'corrected_at',
])]
class Attendance extends Model
{
    use HasFactory;

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'clock_in_time' => 'datetime',
            'clock_out_time' => 'datetime',
            'clock_in_latitude' => 'decimal:8',
            'clock_in_longitude' => 'decimal:8',
            'clock_out_latitude' => 'decimal:8',
            'clock_out_longitude' => 'decimal:8',
            'is_late' => 'boolean',
            'corrected_at' => 'datetime',
        ];
    }
}
