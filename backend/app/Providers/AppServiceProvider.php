<?php

namespace App\Providers;

use App\Services\Transcription\Transcriber;
use App\Services\Transcription\WhisperTranscriber;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(Transcriber::class, WhisperTranscriber::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
