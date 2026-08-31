<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::table('departments')->where('name', 'Manager')->exists()
            && ! DB::table('departments')->where('name', 'Executive')->exists()) {
            DB::table('departments')->where('name', 'Manager')->update(['name' => 'Executive']);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('departments')->where('name', 'Executive')->exists()
            && ! DB::table('departments')->where('name', 'Manager')->exists()) {
            DB::table('departments')->where('name', 'Executive')->update(['name' => 'Manager']);
        }
    }
};
