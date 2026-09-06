<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Only cleans up after a PHP process that died mid-run; PodmanRunner removes
// its own scratch directory on every normal and failed run.
Schedule::command('runner:prune')->hourly();
