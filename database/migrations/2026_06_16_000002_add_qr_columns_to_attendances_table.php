<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->boolean('qr_clock_in')->default(false)->after('proxied_clock_out_by');
            $table->boolean('qr_clock_out')->default(false)->after('qr_clock_in');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['qr_clock_in', 'qr_clock_out']);
        });
    }
};
