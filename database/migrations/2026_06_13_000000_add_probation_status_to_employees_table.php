<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE employees DROP CONSTRAINT employees_employment_status_check');
            DB::statement("ALTER TABLE employees ADD CONSTRAINT employees_employment_status_check CHECK (employment_status IN ('active', 'probation', 'resigned', 'terminated'))");
        } else {
            Schema::table('employees', function (Blueprint $table) {
                $table->enum('employment_status', ['active', 'probation', 'resigned', 'terminated'])->default('active')->change();
            });
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->date('probation_end_date')->nullable()->after('employment_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('probation_end_date');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE employees DROP CONSTRAINT employees_employment_status_check');
            DB::statement("ALTER TABLE employees ADD CONSTRAINT employees_employment_status_check CHECK (employment_status IN ('active', 'resigned', 'terminated'))");
        } else {
            Schema::table('employees', function (Blueprint $table) {
                $table->enum('employment_status', ['active', 'resigned', 'terminated'])->default('active')->change();
            });
        }
    }
};
