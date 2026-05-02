<?php

return [
    'company_settings' => [
        'company_name' => env('HR_COMPANY_NAME', env('APP_NAME', 'HR Management System')),
        'company_logo' => null,
        'company_address' => null,
        'company_phone' => null,
        'company_email' => env('HR_COMPANY_EMAIL'),
        'office_latitude' => null,
        'office_longitude' => null,
        'allowed_radius_meters' => 100,
        'working_start_time' => '08:00:00',
        'working_end_time' => '17:00:00',
        'working_days' => [
            'monday',
            'tuesday',
            'wednesday',
            'thursday',
            'friday',
            'saturday',
        ],
        'salary_currency' => 'USD',
        'payroll_day_rate' => 26,
    ],
];
