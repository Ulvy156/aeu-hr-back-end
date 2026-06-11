<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'title',
    'department_id',
    'description',
    'required_headcount',
    'filled_headcount',
    'target_hiring_date',
    'status',
    'created_by',
])]
class RecruitmentVacancy extends Model
{
    use HasFactory;

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(RecruitmentCandidate::class, 'vacancy_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'required_headcount' => 'integer',
            'filled_headcount' => 'integer',
            'target_hiring_date' => 'date',
        ];
    }
}
