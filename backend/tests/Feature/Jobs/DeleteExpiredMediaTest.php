<?php

use App\Enums\UploadStatus;
use App\Jobs\DeleteExpiredMedia;
use App\Models\Upload;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    $this->freezeTime();
});

function finishedUpload(UploadStatus $status, int $hoursAgo): Upload
{
    $upload = Upload::factory()->uploaded()->create(['status' => $status, 'updated_at' => now()->subHours($hoursAgo)]);
    Storage::disk('local')->put($upload->path, 'media');
    Storage::disk('local')->put($upload->transcriptPath(), '{}');
    Storage::disk('local')->put($upload->subtitlePath('srt'), 'subtitles');

    return $upload;
}

it('deletes the media of finished uploads past the retention period but keeps transcript and subtitles', function (UploadStatus $status) {
    $upload = finishedUpload($status, 24);
    $media = $upload->path;

    DeleteExpiredMedia::dispatch();

    Storage::disk('local')->assertMissing($media);
    Storage::disk('local')->assertExists([$upload->transcriptPath(), $upload->subtitlePath('srt')]);
    expect($upload->fresh()->path)->toBeNull();
    expect($upload->fresh()->status)->toBe($status);
})->with([
    'ready' => UploadStatus::Ready,
    'failed' => UploadStatus::Failed,
]);

it('keeps the media of uploads still inside the retention period', function () {
    $upload = finishedUpload(UploadStatus::Ready, 23);

    DeleteExpiredMedia::dispatch();

    Storage::disk('local')->assertExists($upload->path);
    expect($upload->fresh()->path)->not->toBeNull();
});

it('never deletes the media of uploads that are still being processed', function (UploadStatus $status) {
    $upload = finishedUpload($status, 48);

    DeleteExpiredMedia::dispatch();

    Storage::disk('local')->assertExists($upload->path);
})->with([
    'uploaded' => UploadStatus::Uploaded,
    'transcribing' => UploadStatus::Transcribing,
]);

it('uses the configured retention period', function () {
    config(['uploads.media_retention_hours' => 2]);
    $expired = finishedUpload(UploadStatus::Ready, 2);
    $recent = finishedUpload(UploadStatus::Ready, 1);

    DeleteExpiredMedia::dispatch();

    expect($expired->fresh()->path)->toBeNull();
    expect($recent->fresh()->path)->not->toBeNull();
});

it('still deletes the rest of an upload whose media is gone', function () {
    $upload = finishedUpload(UploadStatus::Ready, 24);
    DeleteExpiredMedia::dispatch();

    Sanctum::actingAs($upload->user);
    $this->deleteJson("/api/uploads/{$upload->id}")->assertNoContent();

    $this->assertModelMissing($upload);
    Storage::disk('local')->assertMissing([$upload->transcriptPath(), $upload->subtitlePath('srt')]);
});

it('is scheduled to run hourly', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === DeleteExpiredMedia::class);

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('0 * * * *');
});
