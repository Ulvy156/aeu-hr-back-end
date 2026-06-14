<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE leave_requests DROP CONSTRAINT leave_requests_leave_type_check');
            DB::statement("ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_leave_type_check CHECK (leave_type IN ('annual', 'sick', 'maternity', 'unpaid', 'special'))");
        } else {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->enum('leave_type', ['annual', 'sick', 'maternity', 'unpaid', 'special'])->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE leave_requests DROP CONSTRAINT leave_requests_leave_type_check');
            DB::statement("ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_leave_type_check CHECK (leave_type IN ('annual', 'sick', 'maternity', 'unpaid'))");
        } else {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->enum('leave_type', ['annual', 'sick', 'maternity', 'unpaid'])->change();
            });
        }
    }
};
