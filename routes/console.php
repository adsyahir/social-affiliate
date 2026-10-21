<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Times are in the campaign timezone (Malaysia by default), not the server's UTC.
Schedule::command('social:refresh-tokens')->dailyAt('03:00')->timezone(config('services.campaigns.timezone'))->withoutOverlapping();
Schedule::command('campaigns:run')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('insights:fetch')->dailyAt('06:00')->timezone(config('services.campaigns.timezone'))->withoutOverlapping();
