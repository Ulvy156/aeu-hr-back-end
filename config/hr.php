<?php

return [
    'company_settings' => [
        'company_name' => env('HR_COMPANY_NAME', env('APP_NAME', 'HR Management System')),
        'company_logo' => null,
        'company_address' => null,
        'company_phone' => null,
        'company_email' => env('HR_COMPANY_EMAIL'),
        'office_latitude' => 11.55830000,
        'office_longitude' => 104.91210000,
        'allowed_radius_meters' => 30,
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
    'employment' => [
        'probation_period_months' => 3,
    ],
    'leave' => [
        'entitlements' => [
            'annual' => 18.0,
            'sick' => 7.0,
            'special' => 7.0,
            'maternity' => 90.0,
        ],
    ],
    'auth' => [
        'access_token_expiration_days' => (int) env('ACCESS_TOKEN_EXPIRATION_DAYS', 7),
    ],
    'payroll' => [
        'tax_brackets' => [
            ['up_to' => 375.00, 'rate' => 0.00],
            ['up_to' => 500.00, 'rate' => 0.05],
            ['up_to' => 2125.00, 'rate' => 0.10],
            ['up_to' => 3125.00, 'rate' => 0.15],
            ['up_to' => null, 'rate' => 0.20],
        ],
        'nssf' => [
            'salary_threshold' => 300.00,
            'lower_deduction' => 4.00,
            'higher_deduction' => 6.00,
        ],
    ],
];
