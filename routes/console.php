<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sweep pending_extractions rows that never got their callback. Runs
// every 5 min — finer-grained than this is overkill (callbacks land
// within minutes when they land at all), coarser leaves "awaiting
// callback" badges sticking around longer than they need to.
Schedule::command('ai:reap-pending')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();
