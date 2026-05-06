<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('proxied_clock_in_by')
                ->nullable()
                ->after('corrected_at')
                ->index()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('proxied_clock_out_by')
                ->nullable()
                ->after('proxied_clock_in_by')
                ->index()
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropForeign(['proxied_clock_in_by']);
            $table->dropForeign(['proxied_clock_out_by']);
            $table->dropColumn(['proxied_clock_in_by', 'proxied_clock_out_by']);
        });
    }
};
