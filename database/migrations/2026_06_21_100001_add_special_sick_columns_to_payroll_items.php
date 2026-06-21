<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->decimal('special_sick_leave_days', 5, 2)->default(0)->after('maternity_leave_days');
            $table->decimal('special_sick_deduction', 15, 2)->default(0)->after('maternity_deduction');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->dropColumn(['special_sick_leave_days', 'special_sick_deduction']);
        });
    }
};
