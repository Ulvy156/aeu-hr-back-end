<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'company_name',
    'company_logo',
    'company_address',
    'company_phone',
    'company_email',
    'office_latitude',
    'office_longitude',
    'allowed_radius_meters',
    'working_start_time',
    'working_end_time',
    'working_days',
    'salary_currency',
    'payroll_day_rate',
])]
class CompanySetting extends Model
{
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'allowed_radius_meters' => 100,
        'working_start_time' => '08:00:00',
        'working_end_time' => '17:00:00',
        'working_days' => '["monday","tuesday","wednesday","thursday","friday","saturday"]',
        'salary_currency' => 'USD',
        'payroll_day_rate' => 26,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'working_days' => 'array',
            'office_latitude' => 'decimal:8',
            'office_longitude' => 'decimal:8',
            'allowed_radius_meters' => 'integer',
            'payroll_day_rate' => 'integer',
        ];
    }
}
