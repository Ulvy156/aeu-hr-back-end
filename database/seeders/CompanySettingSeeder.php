<?php

namespace Database\Seeders;

use App\Services\CompanySettingService;
use Illuminate\Database\Seeder;

class CompanySettingSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        app(CompanySettingService::class)->ensureDefaultExists();
    }
}
