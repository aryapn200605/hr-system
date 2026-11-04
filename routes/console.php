<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Times follow the application timezone (WIB).
Schedule::command('app:prune-expired-tokens')->dailyAt('02:00')->withoutOverlapping();
