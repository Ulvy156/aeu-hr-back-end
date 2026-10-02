<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;

class PayrollDemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $periodStart = Carbon::create(2026, 9, 1)->startOfDay();
        $periodEnd = Carbon::create(2026, 9, 27)->startOfDay();

        $employees = Employee::query()->get();
        $existingAttendance = Attendance::query()
            ->whereBetween('attendance_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->get(['employee_id', 'attendance_date'])
            ->mapWithKeys(fn (Attendance $attendance): array => [
                $attendance->employee_id.':'.$attendance->attendance_date->toDateString() => true,
            ]);
        $attendanceRows = [];

        foreach ($employees as $employee) {
            foreach (CarbonPeriod::create($periodStart, $periodEnd) as $date) {
                $dateString = $date->toDateString();
                $attendanceKey = $employee->id.':'.$dateString;

                if ($existingAttendance->has($attendanceKey)) {
                    continue;
                }

                $isLeaveDate = $dateString === '2026-09-08'
                    || $dateString === '2026-09-15'
                    || $dateString === '2026-09-22';
                $status = $isLeaveDate
                    ? 'absent'
                    : ($date->day % 11 === 0 ? 'missing_clock_out' : ($date->day % 7 === 0 ? 'late' : 'present'));

                $attendanceRows[] = [
                    'employee_id' => $employee->id,
                    'attendance_date' => $dateString,
                    'clock_in_time' => $status === 'absent' ? null : $date->copy()->setTime(8, 0),
                    'clock_out_time' => in_array($status, ['absent', 'missing_clock_out'], true)
                        ? null
                        : $date->copy()->setTime(17, 0),
                    'status' => $status,
                    'is_late' => $status === 'late',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach (array_chunk($attendanceRows, 500) as $rows) {
            Attendance::query()->insert($rows);
        }

        $existingLeaves = LeaveRequest::query()
            ->whereBetween('start_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->get(['employee_id', 'leave_type', 'start_date'])
            ->mapWithKeys(fn (LeaveRequest $leave): array => [
                $leave->employee_id.':'.$leave->leave_type.':'.$leave->start_date->toDateString() => true,
            ]);
        $leaveRows = [];

        foreach ($employees as $employee) {
            $leaveType = $employee->id % 2 === 0 ? 'annual' : 'unpaid';
            $leaveDate = $employee->id % 2 === 0 ? '2026-09-08' : '2026-09-15';
            $timestamp = now();

            foreach ([
                [$leaveType, $leaveDate, 'full_day', 1, 'Demo payroll testing leave'],
                ['special_sick', '2026-09-22', 'half_day', 0.5, 'Demo half-day special sick leave'],
            ] as [$type, $date, $durationType, $totalDays, $reason]) {
                $leaveKey = $employee->id.':'.$type.':'.$date;

                if ($existingLeaves->has($leaveKey)) {
                    continue;
                }

                $leaveRows[] = [
                    'employee_id' => $employee->id,
                    'leave_type' => $type,
                    'start_date' => $date,
                    'end_date' => $date,
                    'duration_type' => $durationType,
                    'total_days' => $totalDays,
                    'reason' => $reason,
                    'status' => 'approved',
                    'hr_approval_status' => 'approved',
                    'ceo_approval_status' => 'approved',
                    'hr_approved_at' => $timestamp,
                    'ceo_approved_at' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }
        }

        foreach (array_chunk($leaveRows, 500) as $rows) {
            LeaveRequest::query()->insert($rows);
        }

        $this->command?->info("Employees processed: ".$employees->count());
        $this->command?->info("Attendance rows created: ".count($attendanceRows));
        $this->command?->info("Leave requests created: ".count($leaveRows));
    }
}
