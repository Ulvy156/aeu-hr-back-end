<?php

namespace Database\Seeders;

use App\Models\AnnouncementCategory;
use Illuminate\Database\Seeder;

class AnnouncementCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'General',
            'HR',
            'Payroll',
            'Policy',
            'Holiday',
            'Event',
            'Training',
            'Safety',
        ];

        foreach ($categories as $name) {
            AnnouncementCategory::query()->updateOrCreate(['name' => $name], ['status' => 'active']);
        }
    }
}
