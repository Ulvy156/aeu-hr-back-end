<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The original special-sick migration updated PostgreSQL only.
        if (in_array(DB::getDriverName(), ['sqlite', 'mysql', 'mariadb'], true)) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->enum('leave_type', ['annual', 'sick', 'maternity', 'unpaid', 'special', 'special_sick'])->change();
            });
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['sqlite', 'mysql', 'mariadb'], true)) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->enum('leave_type', ['annual', 'sick', 'maternity', 'unpaid', 'special'])->change();
            });
        }
    }
};
