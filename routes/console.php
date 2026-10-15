<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('attendance:check-missing-clock-in')
    ->dailyAt('09:30')
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('attendance:mark-missing-clock-out --scheduled')
    ->dailyAt('17:30')
    ->withoutOverlapping();

Schedule::command('attendance:mark-absent')
    ->dailyAt('23:00')
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('recruitment:close-due-vacancies')
    ->dailyAt('00:05')
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('employees:apply-due-promotions')
    ->dailyAt('01:00')
    ->timezone('Asia/Phnom_Penh')
    ->onOneServer()
    ->withoutOverlapping();
