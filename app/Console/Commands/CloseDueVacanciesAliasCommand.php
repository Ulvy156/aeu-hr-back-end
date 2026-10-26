<?php

namespace App\Console\Commands;

use App\Services\RecruitmentVacancyService;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('recruitment:close-due-vacancies {--date= : Process date cutoff (Y-m-d); defaults to today}')]
#[Description('Alias for recruitment:process-due-vacancies (open due scheduled and close due open vacancies)')]
class CloseDueVacanciesAliasCommand extends Command
{
    public function handle(RecruitmentVacancyService $vacancyService): int
    {
        $option = $this->option('date');
        $date = is_string($option) && $option !== '' ? $option : today()->toDateString();

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! Carbon::hasFormat($date, 'Y-m-d')) {
            $this->error('The date must use YYYY-MM-DD format.');

            return self::FAILURE;
        }

        $result = $vacancyService->processDue($date);
        $this->info("Opened {$result['opened']} and closed {$result['closed']} vacancy/vacancies for {$date}.");

        return self::SUCCESS;
    }
}
