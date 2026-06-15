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
        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_batch_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->index()->constrained()->cascadeOnDelete();
            $table->decimal('base_salary', 15, 2);
            $table->decimal('daily_rate', 10, 2);
            $table->decimal('working_days', 5, 2)->default(0);
            $table->decimal('present_days', 5, 2)->default(0);
            $table->decimal('absent_days', 5, 2)->default(0);
            $table->decimal('unpaid_leave_days', 5, 2)->default(0);
            $table->decimal('maternity_leave_days', 5, 2)->default(0);
            $table->decimal('gross_salary', 15, 2);
            $table->decimal('unpaid_deduction', 15, 2)->default(0);
            $table->decimal('absence_deduction', 15, 2)->default(0);
            $table->decimal('maternity_deduction', 15, 2)->default(0);
            $table->decimal('taxable_salary', 15, 2);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('nssf_deduction', 15, 2)->default(0);
            $table->json('tax_breakdown')->nullable();
            $table->decimal('net_salary', 15, 2);
            $table->enum('status', ['draft', 'locked'])->default('draft')->index();
            $table->timestamps();

            $table->unique(['payroll_batch_id', 'employee_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_items');
    }
};
