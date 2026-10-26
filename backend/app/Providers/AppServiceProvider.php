<?php

namespace App\Providers;

use App\Services\Transcription\Transcriber;
use App\Services\Transcription\WhisperTranscriber;
use App\Services\Translation\LocalTranslator;
use App\Services\Translation\Translator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(Transcriber::class, WhisperTranscriber::class);
        $this->app->bind(Translator::class, LocalTranslator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('uploads', function (Request $request): Limit {
            $perHour = (int) config('uploads.max_per_hour');

            return Limit::perHour($perHour)
                ->by($request->user()->id)
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => "You can start up to {$perHour} uploads per hour. Please try again later.",
                ], 429, $headers));
        });
    }
}
