<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        $this->call(CompanySettingSeeder::class);
        $this->call(DepartmentSeeder::class);
        $this->call(PositionSeeder::class);
        $this->call(AnnouncementCategorySeeder::class);

        // Admin user (no employee record — system account)
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'System Admin', 'password' => 'password', 'status' => 'active']
        );

        if ($admin->roles->isEmpty()) {
            $admin->assignRole('admin');
        }

        $this->call(UserSeeder::class);
    }
}
