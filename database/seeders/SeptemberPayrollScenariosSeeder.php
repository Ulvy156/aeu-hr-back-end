<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\CompanySettingService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SeptemberPayrollScenariosSeeder extends Seeder
{
    public function run(): void
    {
        $scenarios = [
            ['id' => 'DEMO-SEP26-01', 'name' => 'Demo: Full Attendance', 'join_date' => '2026-08-01', 'missing_dates' => [], 'leave' => null],
            ['id' => 'DEMO-SEP26-02', 'name' => 'Demo: September 8 Starter', 'join_date' => '2026-09-08', 'missing_dates' => [], 'leave' => null],
            ['id' => 'DEMO-SEP26-03', 'name' => 'Demo: Missing Attendance', 'join_date' => '2026-08-01', 'missing_dates' => ['2026-09-10', '2026-09-21'], 'leave' => null],
            ['id' => 'DEMO-SEP26-04', 'name' => 'Demo: Paid Leave', 'join_date' => '2026-08-01', 'missing_dates' => [], 'leave' => ['type' => 'annual', 'date' => '2026-09-15']],
            ['id' => 'DEMO-SEP26-05', 'name' => 'Demo: Unpaid Leave', 'join_date' => '2026-08-01', 'missing_dates' => [], 'leave' => ['type' => 'unpaid', 'date' => '2026-09-22']],
        ];

        DB::transaction(function () use ($scenarios): void {
            $workingDays = app(CompanySettingService::class)->current()->working_days ?? [];
            $holidays = PublicHoliday::query()
                ->where('status', 'active')
                ->whereBetween('holiday_date', ['2026-09-01', '2026-09-30'])
                ->pluck('holiday_date')
                ->map(fn ($date) => Carbon::parse($date)->toDateString())
                ->all();

            foreach ($scenarios as $scenario) {
                $user = User::query()->firstOrCreate(
                    ['email' => strtolower($scenario['id']).'@example.test'],
                    ['name' => $scenario['name'], 'password' => Str::random(48), 'status' => 'active'],
                );
                $user->assignRole('employee');

                $employee = Employee::query()->updateOrCreate(
                    ['employee_id' => $scenario['id']],
                    [
                        'user_id' => $user->id,
                        'full_name' => $scenario['name'],
                        'join_date' => $scenario['join_date'],
                        'base_salary' => 2600,
                        'employment_status' => 'full-time',
                    ],
                );

                Attendance::query()->where('employee_id', $employee->id)
                    ->whereBetween('attendance_date', ['2026-09-01', '2026-09-30'])
                    ->delete();
                LeaveRequest::query()->where('employee_id', $employee->id)
                    ->whereBetween('start_date', ['2026-09-01', '2026-09-30'])
                    ->delete();

                $attendanceRows = [];

                foreach (CarbonPeriod::create('2026-09-01', '2026-09-30') as $date) {
                    $day = $date->toDateString();

                    if ($day < $scenario['join_date']
                        || ! in_array(strtolower($date->format('l')), $workingDays, true)
                        || in_array($day, $holidays, true)
                        || in_array($day, $scenario['missing_dates'], true)
                        || $day === ($scenario['leave']['date'] ?? null)) {
                        continue;
                    }

                    $attendanceRows[] = [
                        'employee_id' => $employee->id,
                        'attendance_date' => $day,
                        'clock_in_time' => $day.' 08:00:00',
                        'clock_out_time' => $day.' 17:00:00',
                        'status' => 'present',
                        'is_late' => false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                Attendance::query()->insert($attendanceRows);

                if ($scenario['leave']) {
                    LeaveRequest::query()->create([
                        'employee_id' => $employee->id,
                        'leave_type' => $scenario['leave']['type'],
                        'start_date' => $scenario['leave']['date'],
                        'end_date' => $scenario['leave']['date'],
                        'duration_type' => 'full_day',
                        'total_days' => 1,
                        'reason' => 'September payroll demo scenario',
                        'status' => 'approved',
                        'hr_approval_status' => 'approved',
                        'ceo_approval_status' => 'approved',
                        'hr_approved_at' => now(),
                        'ceo_approved_at' => now(),
                    ]);
                }
            }
        });

        $this->command?->info('Seeded five September 2026 payroll scenarios.');
    }
}
