<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->decimal('maternity_leave_days', 5, 2)->default(0)->after('unpaid_leave_days');
            $table->decimal('maternity_deduction', 15, 2)->default(0)->after('absence_deduction');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->dropColumn(['maternity_leave_days', 'maternity_deduction']);
        });
    }
};
