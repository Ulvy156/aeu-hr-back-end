<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Use stored salary bases so historical attendance and approvals stay intact.
        DB::transaction(function (): void {
            DB::table('payroll_items')->orderBy('id')->chunkById(500, function ($items): void {
                foreach ($items as $item) {
                    $salary = max(0, (float) $item->taxable_salary);
                    $nssf = $salary <= 0 ? 0 : ($salary < 300 ? 4 : 6);
                    DB::table('payroll_items')->where('id', $item->id)->update([
                        'tax_rate' => 0,
                        'tax_amount' => 0,
                        'tax_breakdown' => json_encode([]),
                        'nssf_deduction' => $nssf,
                        'net_salary' => round(max(0, $salary - $nssf), 2),
                    ]);
                }
            });
        });
    }

    public function down(): void
    {
        // Prior tax snapshots cannot be reconstructed; restore the saved backup if needed.
    }
};
