<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'leave_type',
    'start_date',
    'end_date',
    'duration_type',
    'total_days',
    'reason',
    'status',
    'hr_approval_status',
    'hr_approved_by',
    'hr_approved_at',
    'ceo_approval_status',
    'ceo_approved_by',
    'ceo_approved_at',
    'rejection_reason',
    'cancelled_at',
])]
class LeaveRequest extends Model
{
    use HasFactory;

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function hrApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hr_approved_by');
    }

    public function ceoApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ceo_approved_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'total_days' => 'decimal:2',
            'hr_approved_at' => 'datetime',
            'ceo_approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
