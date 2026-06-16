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
        Schema::create('recruitment_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vacancy_id')->index()->constrained('recruitment_vacancies')->restrictOnDelete();
            $table->string('full_name');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->enum('source', ['facebook', 'telegram', 'linkedin', 'referral', 'walk_in', 'email', 'other'])->index();
            $table->string('cv_path');
            $table->string('cv_name');
            $table->unsignedBigInteger('cv_size');
            $table->enum('status', [
                'new',
                'shortlisted',
                'contacting_candidate',
                'interview',
                'offer_extended',
                'offer_accepted',
                'hired',
                'company_rejected',
                'candidate_declined',
                'no_show',
            ])->default('new')->index();
            $table->date('interview_date')->nullable();
            $table->text('notes')->nullable();
            $table->longText('outcome_reason')->nullable();
            $table->foreignId('created_by')->index()->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['vacancy_id', 'phone']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recruitment_candidates');
    }
};
