<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->decimal('nssf_deduction', 15, 2)->default(0)->after('tax_amount');
            $table->json('tax_breakdown')->nullable()->after('nssf_deduction');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->dropColumn(['nssf_deduction', 'tax_breakdown']);
        });
    }
};
