<?php

namespace App\Console\Commands;

use App\Services\RecruitmentVacancyService;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('recruitment:close-due-vacancies {--date= : Close date cutoff (Y-m-d); defaults to today}')]
#[Description('Automatically close vacancies whose close date has arrived')]
class CloseDueVacanciesCommand extends Command
{
    public function handle(RecruitmentVacancyService $vacancyService): int
    {
        $option = $this->option('date');
        $date = is_string($option) && $option !== '' ? $option : today()->toDateString();

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! Carbon::hasFormat($date, 'Y-m-d')) {
            $this->error('The date must use YYYY-MM-DD format.');

            return self::FAILURE;
        }

        $closedCount = $vacancyService->closeDue($date);
        $this->info("Closed {$closedCount} due vacancy/vacancies for {$date}.");

        return self::SUCCESS;
    }
}
