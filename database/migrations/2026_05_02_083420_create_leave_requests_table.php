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
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->index()->constrained()->cascadeOnDelete();
            $table->enum('leave_type', ['annual', 'sick', 'maternity', 'unpaid'])->index();
            $table->date('start_date')->index();
            $table->date('end_date')->index();
            $table->enum('duration_type', ['full_day', 'half_day'])->default('full_day');
            $table->decimal('total_days', 5, 2);
            $table->text('reason');
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending')->index();
            $table->enum('hr_approval_status', ['pending', 'approved', 'rejected'])->default('pending')->index();
            $table->foreignId('hr_approved_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamp('hr_approved_at')->nullable();
            $table->enum('ceo_approval_status', ['pending', 'approved', 'rejected'])->default('pending')->index();
            $table->foreignId('ceo_approved_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamp('ceo_approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['start_date', 'end_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
