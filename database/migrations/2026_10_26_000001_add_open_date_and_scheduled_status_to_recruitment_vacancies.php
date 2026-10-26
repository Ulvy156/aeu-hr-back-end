<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_vacancies', function (Blueprint $table) {
            $table->date('open_date')->nullable()->after('filled_headcount')->index();
        });

        DB::table('recruitment_vacancies')
            ->whereNull('open_date')
            ->update(['open_date' => DB::raw('target_hiring_date')]);

        Schema::table('recruitment_vacancies', function (Blueprint $table) {
            $table->date('open_date')->nullable(false)->change();
        });

        $this->expandStatusEnum(['scheduled', 'open', 'closed']);

        $today = now()->toDateString();

        DB::table('recruitment_vacancies')
            ->where('status', 'open')
            ->whereDate('open_date', '>', $today)
            ->update(['status' => 'scheduled']);
    }

    public function down(): void
    {
        DB::table('recruitment_vacancies')
            ->where('status', 'scheduled')
            ->update(['status' => 'open']);

        $this->expandStatusEnum(['open', 'closed']);

        Schema::table('recruitment_vacancies', function (Blueprint $table) {
            $table->dropIndex(['open_date']);
            $table->dropColumn('open_date');
        });
    }

    /**
     * @param  list<string>  $values
     */
    protected function expandStatusEnum(array $values): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE recruitment_vacancies DROP CONSTRAINT IF EXISTS recruitment_vacancies_status_check');
            $array = implode(',', array_map(fn (string $value): string => "'{$value}'", $values));
            DB::statement("ALTER TABLE recruitment_vacancies ADD CONSTRAINT recruitment_vacancies_status_check CHECK (status::text = ANY (ARRAY[{$array}]::text[]))");

            return;
        }

        if (in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            Schema::table('recruitment_vacancies', function (Blueprint $table) use ($values) {
                $table->enum('status', $values)->default('open')->change();
            });
        }
    }
};
