<?php

use App\Enums\UploadStatus;
use App\Models\Upload;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->freezeTime();
    config(['uploads.retention_hours' => 6]);
});

function prune(): void
{
    test()->artisan('model:prune', ['--model' => [Upload::class]])->assertSuccessful();
}

it('deletes finished uploads nobody downloaded, with their files', function (string $state) {
    $upload = Upload::factory()->{$state}()->create(['finished_at' => now()->subHours(6), 'subtitle_format' => 'both']);
    Storage::disk('local')->put($upload->subtitlePath('srt'), 'x');
    Storage::disk('local')->put($upload->subtitlePath('vtt'), 'x');

    prune();

    $this->assertModelMissing($upload);
    Storage::disk('local')->assertMissing([$upload->subtitlePath('srt'), $upload->subtitlePath('vtt')]);
})->with(['ready', 'failed']);

it('keeps finished uploads that are still inside the retention window', function () {
    $upload = Upload::factory()->ready()->create(['finished_at' => now()->subHours(6)->addSecond()]);

    prune();

    $this->assertModelExists($upload);
});

it('deletes uploads that were never completed, with their chunks', function () {
    $abandoned = Upload::factory()->create(['created_at' => now()->subHours(6)]);
    $recent = Upload::factory()->create(['created_at' => now()->subHours(6)->addSecond()]);
    Storage::disk('local')->put("chunks/{$abandoned->id}/0", 'data');

    prune();

    $this->assertModelMissing($abandoned);
    $this->assertModelExists($recent);
    Storage::disk('local')->assertMissing("chunks/{$abandoned->id}");
});

it('keeps uploads that are still being processed, however long they take', function (UploadStatus $status) {
    $upload = Upload::factory()->uploaded()->create(['status' => $status, 'created_at' => now()->subHours(23)]);

    prune();

    $this->assertModelExists($upload);
})->with([
    'assembling' => UploadStatus::Assembling,
    'waiting to transcribe' => UploadStatus::Uploaded,
    'transcribing' => UploadStatus::Transcribing,
]);

it('deletes uploads stuck in processing for a day', function () {
    $stuck = Upload::factory()->uploaded()->create(['status' => UploadStatus::Transcribing, 'created_at' => now()->subDay()]);
    Storage::disk('local')->put($stuck->path, 'media');

    prune();

    $this->assertModelMissing($stuck);
    Storage::disk('local')->assertMissing($stuck->path);
});

it('uses the configured retention window', function () {
    config(['uploads.retention_hours' => 1]);
    $expired = Upload::factory()->ready()->create(['finished_at' => now()->subHour()]);
    $recent = Upload::factory()->ready()->create(['finished_at' => now()->subMinutes(59)]);

    prune();

    $this->assertModelMissing($expired);
    $this->assertModelExists($recent);
});

it('schedules pruning to run hourly', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command, 'model:prune'));

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('0 * * * *');
});
