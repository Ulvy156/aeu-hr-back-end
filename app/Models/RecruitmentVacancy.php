<?php

namespace App\Models;

use Carbon\CarbonInterface;
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
    'open_date',
    'target_hiring_date',
    'close_date',
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

    public function acceptsApplications(?CarbonInterface $on = null): bool
    {
        $onDate = ($on ?? now())->toDateString();
        $openDate = $this->open_date?->toDateString();
        $closeDate = $this->close_date?->toDateString();

        if ($this->status !== 'open' || $openDate === null) {
            return false;
        }

        if ($onDate < $openDate) {
            return false;
        }

        if ($closeDate !== null && $onDate > $closeDate) {
            return false;
        }

        return true;
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
            'open_date' => 'date',
            'target_hiring_date' => 'date',
            'close_date' => 'date',
        ];
    }
}
