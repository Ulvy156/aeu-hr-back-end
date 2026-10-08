<?php

namespace App\Console\Commands;

use App\Services\AttendanceService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('attendance:mark-missing-clock-out {--date= : Optional attendance date (Y-m-d)} {--scheduled : Run the daily HR follow-up sweep for today}')]
#[Description('Flag open clock-ins for HR follow-up')]
class MarkMissingClockOutCommand extends Command
{
    public function handle(AttendanceService $attendanceService): int
    {
        $date = $this->option('date');
        $date = is_string($date) && $date !== '' ? $date : null;

        $result = $attendanceService->markMissingClockOut($date, (bool) $this->option('scheduled'));

        $this->info(sprintf(
            'Marked %d attendance record(s) as missing clock out%s.',
            $result['updated_count'],
            $result['attendance_date'] ? ' for '.$result['attendance_date'] : '',
        ));

        return self::SUCCESS;
    }
}
