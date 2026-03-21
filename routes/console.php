<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Ping the central server every 15 minutes to sync settings and confirm subscription status
Schedule::command('server:sync')->everyFifteenMinutes()->withoutOverlapping();
