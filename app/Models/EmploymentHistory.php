<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'field',
    'old_value',
    'new_value',
    'effective_date',
    'changed_by',
])]
class EmploymentHistory extends Model
{
    use HasFactory;

    public const FIELD_DEPARTMENT_ID = 'department_id';

    public const FIELD_POSITION_ID = 'position_id';

    public const FIELD_BASE_SALARY = 'base_salary';

    public const FIELD_EMPLOYMENT_STATUS = 'employment_status';

    public const FIELD_PROBATION_END_DATE = 'probation_end_date';

    public const TRACKED_FIELDS = [
        self::FIELD_DEPARTMENT_ID,
        self::FIELD_POSITION_ID,
        self::FIELD_BASE_SALARY,
        self::FIELD_EMPLOYMENT_STATUS,
        self::FIELD_PROBATION_END_DATE,
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_value' => 'array',
            'new_value' => 'array',
            'effective_date' => 'date',
        ];
    }
}
