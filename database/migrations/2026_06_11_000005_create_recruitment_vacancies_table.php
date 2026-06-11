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
        Schema::create('recruitment_vacancies', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('department_id')->index()->constrained('departments')->restrictOnDelete();
            $table->longText('description');
            $table->unsignedInteger('required_headcount');
            $table->unsignedInteger('filled_headcount')->default(0);
            $table->date('target_hiring_date');
            $table->enum('status', ['open', 'closed'])->default('open')->index();
            $table->foreignId('created_by')->index()->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recruitment_vacancies');
    }
};
