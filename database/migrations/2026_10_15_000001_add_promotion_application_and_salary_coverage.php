<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_upgrade_requests', function (Blueprint $table) {
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('application_failed_at')->nullable();
            $table->text('application_error')->nullable();
            $table->index(['status', 'applied_at', 'effective_date'], 'upgrade_requests_due_index');
        });
        DB::table('employee_upgrade_requests')->where('status', 'approved')->update([
            'applied_at' => DB::raw('COALESCE(reviewed_at, updated_at, created_at)'),
        ]);
        Schema::table('employees', function (Blueprint $table) {
            $table->date('salary_history_start_month')->nullable();
            $table->decimal('salary_history_baseline', 15, 2)->nullable();
            $table->unsignedBigInteger('salary_history_baseline_history_id')->default(0);
        });
        // Existing records have no guarantee of complete historical salary changes.
        DB::table('employees')->update([
            'salary_history_start_month' => now('Asia/Phnom_Penh')->startOfMonth()->toDateString(),
            'salary_history_baseline' => DB::raw('base_salary'),
            'salary_history_baseline_history_id' => DB::table('employment_histories')->max('id') ?? 0,
        ]);
        Schema::table('employment_histories', function (Blueprint $table) {
            $table->string('source')->nullable();
            $table->index(['employee_id', 'field', 'effective_date'], 'salary_history_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::table('employment_histories', function (Blueprint $table) {
            $table->dropIndex('salary_history_lookup_index');
            $table->dropColumn('source');
        });
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn(['salary_history_start_month', 'salary_history_baseline', 'salary_history_baseline_history_id']));
        Schema::table('employee_upgrade_requests', function (Blueprint $table) {
            $table->dropIndex('upgrade_requests_due_index');
            $table->dropColumn(['applied_at', 'application_failed_at', 'application_error']);
        });
    }
};
