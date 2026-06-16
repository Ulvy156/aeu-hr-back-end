<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_qr_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('generated_by')
                ->nullable()
                ->index()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_qr_tokens');
    }
};
