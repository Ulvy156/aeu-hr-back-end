<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
        });

        DB::table('announcements')
            ->where('status', 'published')
            ->whereNull('published_at')
            ->update([
                'published_by' => DB::raw('approved_by'),
                'published_at' => DB::raw('approved_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('published_by');
            $table->dropColumn('published_at');
        });
    }
};
