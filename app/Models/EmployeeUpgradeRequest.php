<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'status',
    'current_values',
    'proposed_values',
    'effective_date',
    'attachments',
    'requested_by',
    'reviewed_by',
    'reviewed_at',
    'rejection_reason',
])]
class EmployeeUpgradeRequest extends Model
{
    use HasFactory;

    public const UPGRADABLE_FIELDS = [
        'department_id',
        'position_id',
        'base_salary',
        'employment_status',
        'manager_id',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_values' => 'array',
            'proposed_values' => 'array',
            'effective_date' => 'date',
            'attachments' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }
}
