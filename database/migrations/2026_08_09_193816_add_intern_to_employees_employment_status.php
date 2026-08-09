<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS employees_employment_status_check');
            DB::statement("ALTER TABLE employees ADD CONSTRAINT employees_employment_status_check CHECK (employment_status::text = ANY (ARRAY['full-time','probation','intern','resigned','terminated']::text[]))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS employees_employment_status_check');
            DB::statement("ALTER TABLE employees ADD CONSTRAINT employees_employment_status_check CHECK (employment_status::text = ANY (ARRAY['full-time','probation','resigned','terminated']::text[]))");
        }
    }
};
