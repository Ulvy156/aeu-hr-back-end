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
        Schema::table('recruitment_candidates', function (Blueprint $table) {
            $table->dropColumn('interviewer');
            $table->foreignId('interviewer_id')->nullable()->index()->after('interview_date')->constrained('employees')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recruitment_candidates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('interviewer_id');
            $table->string('interviewer')->nullable()->after('interview_date');
        });
    }
};
