<?php

use App\Enums\JobLevel;
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
        Schema::table('positions', function (Blueprint $table) {
            $table->enum('job_level', array_column(JobLevel::cases(), 'value'))
                ->default(JobLevel::Junior->value)
                ->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropIndex(['job_level']);
            $table->dropColumn('job_level');
        });
    }
};
