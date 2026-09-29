<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Publishing runs entirely off the queue: the scheduler only claims due rows and
// hands them to a worker, so a slow provider never blocks the tick.
Schedule::command('publish:due')->everyMinute()->withoutOverlapping();
Schedule::command('publishing:prune-attempts')->daily();

// Engagement and account lifecycle.
//
// `withoutOverlapping` on each of these matters more than it looks: a sweep that
// runs longer than its interval must not stack up behind itself, because the
// per-account jobs it dispatches are already staggered to respect provider rate
// limits and a second overlapping sweep would double that load.
Schedule::command('socialhub:tokens:refresh')
    ->hourly()
    ->withoutOverlapping(55);

Schedule::command('socialhub:comments:sync')
    ->everyFifteenMinutes()
    ->withoutOverlapping(14);

Schedule::command('socialhub:webhooks:prune')
    ->dailyAt('03:30')
    ->withoutOverlapping(30);
