<?php

use App\Jobs\DeleteExpiredMedia;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('model:prune')->daily();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::job(new DeleteExpiredMedia)->hourly();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
