<?php

use App\Enums\EmploymentStatus;
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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('employee_id', 50)->unique();
            $table->string('full_name');
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('phone_number', 50)->nullable();
            $table->text('address')->nullable();
            $table->foreignId('department_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->foreignId('position_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->index()->constrained('employees')->nullOnDelete();
            $table->date('join_date')->index();
            $table->date('last_working_date')->nullable()->index();
            $table->decimal('base_salary', 15, 2)->default(0);
            $table->enum('employment_status', array_column(EmploymentStatus::cases(), 'value'))->default(EmploymentStatus::FullTime->value)->index();
            $table->date('probation_end_date')->nullable();
            $table->text('emergency_contact')->nullable();
            $table->string('profile_photo')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
