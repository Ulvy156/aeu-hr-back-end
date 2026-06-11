<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'vacancy_id',
    'full_name',
    'phone',
    'email',
    'source',
    'cv_path',
    'cv_name',
    'cv_size',
    'status',
    'interview_date',
    'interviewer_id',
    'notes',
    'outcome_reason',
    'created_by',
])]
class RecruitmentCandidate extends Model
{
    use HasFactory;

    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(RecruitmentVacancy::class, 'vacancy_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'interviewer_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cv_size' => 'integer',
            'interview_date' => 'date',
        ];
    }
}
