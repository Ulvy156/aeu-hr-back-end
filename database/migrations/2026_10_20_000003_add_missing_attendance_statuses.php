<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL stores Laravel enums as CHECK constraints; alter via drop/add.
        DB::statement('ALTER TABLE attendances DROP CONSTRAINT IF EXISTS attendances_status_check');
        DB::statement("ALTER TABLE attendances ADD CONSTRAINT attendances_status_check CHECK (status::text = ANY (ARRAY['present','late','absent','missing_clock_out','missing_clock_in','missing_both']::text[]))");
    }

    public function down(): void
    {
        // Refuse a lossy rollback while the new review statuses are in use.
        if (DB::table('attendances')->whereIn('status', ['missing_clock_in', 'missing_both'])->exists()) {
            throw new RuntimeException('Resolve missing clock-in and missing-both records before rolling back this migration.');
        }

        DB::statement('ALTER TABLE attendances DROP CONSTRAINT IF EXISTS attendances_status_check');
        DB::statement("ALTER TABLE attendances ADD CONSTRAINT attendances_status_check CHECK (status::text = ANY (ARRAY['present','late','absent','missing_clock_out']::text[]))");
    }
};
