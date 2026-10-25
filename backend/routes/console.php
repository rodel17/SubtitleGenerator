<?php

use App\Jobs\DeleteOrphanedFiles;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Uploads nobody downloaded (or finished uploading) are deleted after `uploads.retention_hours`
Schedule::command('model:prune')->hourly();
Schedule::job(new DeleteOrphanedFiles)->hourly();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
