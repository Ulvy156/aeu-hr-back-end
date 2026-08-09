<?php

namespace App\Models;

use App\Enums\EmploymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'user_id',
    'employee_id',
    'full_name',
    'gender',
    'date_of_birth',
    'phone_number',
    'address',
    'department_id',
    'position_id',
    'manager_id',
    'join_date',
    'last_working_date',
    'base_salary',
    'employment_status',
    'probation_end_date',
    'intern_end_date',
    'emergency_contact',
    'profile_photo',
    'documents',
])]
class Employee extends Model
{
    use HasFactory, SoftDeletes;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'manager_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function payrollItems(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function employmentHistories(): HasMany
    {
        return $this->hasMany(EmploymentHistory::class)->orderByDesc('effective_date')->orderByDesc('id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    public static function resolveId(?string $employeeCode): ?int
    {
        if ($employeeCode === null) {
            return null;
        }

        return static::where('employee_id', $employeeCode)->value('id');
    }

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'join_date' => 'date',
            'last_working_date' => 'date',
            'probation_end_date' => 'date',
            'intern_end_date' => 'date',
            'base_salary' => 'decimal:2',
            'employment_status' => EmploymentStatus::class,
            'documents' => 'array',
        ];
    }
}
