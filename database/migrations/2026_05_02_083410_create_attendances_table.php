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
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->index()->constrained()->cascadeOnDelete();
            $table->date('attendance_date');
            $table->timestamp('clock_in_time')->nullable();
            $table->timestamp('clock_out_time')->nullable();
            $table->decimal('clock_in_latitude', 10, 8)->nullable();
            $table->decimal('clock_in_longitude', 11, 8)->nullable();
            $table->decimal('clock_out_latitude', 10, 8)->nullable();
            $table->decimal('clock_out_longitude', 11, 8)->nullable();
            $table->enum('status', ['present', 'late', 'absent', 'missing_clock_out']);
            $table->boolean('is_late')->default(false);
            $table->text('correction_reason')->nullable();
            $table->foreignId('corrected_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamp('corrected_at')->nullable();
            $table->foreignId('proxied_clock_in_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('proxied_clock_out_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'attendance_date']);
            $table->index('attendance_date');
            $table->index('status');
            $table->index(['attendance_date', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
