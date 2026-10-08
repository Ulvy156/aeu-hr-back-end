<?php

namespace App\Console\Commands;

use App\Services\AttendanceService;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('attendance:mark-absent {--date= : Attendance date (Y-m-d); defaults to today}')]
#[Description('Mark employees without attendance or approved leave as absent')]
class MarkAbsentCommand extends Command
{
    public function handle(AttendanceService $attendanceService): int
    {
        $option = $this->option('date');
        $date = is_string($option) && $option !== '' ? $option : today()->toDateString();

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            || ! Carbon::hasFormat($date, 'Y-m-d')
            || Carbon::parse($date)->greaterThan(today())) {
            $this->error('The date must not be in the future and must use YYYY-MM-DD format.');

            return self::FAILURE;
        }

        $result = $attendanceService->markAbsent($date);

        $this->info(sprintf(
            'Marked %d employee(s) absent for %s.',
            $result['created_count'],
            $result['attendance_date'],
        ));

        return self::SUCCESS;
    }
}
