<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_vacancies', function (Blueprint $table) {
            $table->date('close_date')->nullable()->after('target_hiring_date')->index();
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_vacancies', function (Blueprint $table) {
            $table->dropIndex(['close_date']);
            $table->dropColumn('close_date');
        });
    }
};
