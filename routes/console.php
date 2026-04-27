<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('queue:prune-batches --hours=48 --unfinished=72')->daily();
Schedule::command('tasks:finalize-expired')->everyMinute();

Schedule::command('tasks:prune-stale')->dailyAt('23:59');
