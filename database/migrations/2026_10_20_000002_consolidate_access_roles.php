<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Synchronize the four access roles and migrate retired role assignments atomically.
        app(RoleSeeder::class)->run();
    }

    public function down(): void
    {
        // Role assignments cannot be reconstructed safely; restore from the pre-migration backup.
    }
};
