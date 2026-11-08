<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE employees SET employee_id = REPLACE(employee_id, 'EMP-', 'PDM-') WHERE employee_id LIKE 'EMP-%'");
    }

    public function down(): void
    {
        DB::statement("UPDATE employees SET employee_id = REPLACE(employee_id, 'PDM-', 'EMP-') WHERE employee_id LIKE 'PDM-%'");
    }
};
