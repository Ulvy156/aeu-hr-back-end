<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            $table->enum('status', ['present', 'late', 'absent', 'missing_clock_out', 'missing_clock_in', 'missing_both'])->change();
        });
    }

    public function down(): void
    {
        // Refuse a lossy rollback while the new review statuses are in use.
        if (DB::table('attendances')->whereIn('status', ['missing_clock_in', 'missing_both'])->exists()) {
            throw new RuntimeException('Resolve missing clock-in and missing-both records before rolling back this migration.');
        }
        Schema::table('attendances', function (Blueprint $table): void {
            $table->enum('status', ['present', 'late', 'absent', 'missing_clock_out'])->change();
        });
    }
};
