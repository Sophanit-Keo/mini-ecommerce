<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The deployment must invoke `php artisan schedule:run` every minute (or run `schedule:work`)
// for this deterministic cleanup task to execute. The command itself re-locks every candidate,
// so overlapping workers cannot release a reservation twice.
Schedule::command('orders:release-expired-reservations --limit=100')
    ->everyMinute()
    ->withoutOverlapping();
