<?php

use App\Jobs\DeleteOrphanedFiles;
use App\Models\Upload;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

it('deletes files left behind by an upload that no longer exists', function () {
    $id = '01a15979-96db-7264-a6ec-88c51a111e09';
    $files = [
        "chunks/{$id}/0",
        "uploads/{$id}.mp4",
        "audio/{$id}/0000.wav",
        "transcripts/{$id}.json",
        "translations/{$id}.json",
        "subtitles/{$id}.srt",
        "subtitles/{$id}.vtt",
    ];
    foreach ($files as $file) {
        Storage::disk('local')->put($file, 'x');
    }

    DeleteOrphanedFiles::dispatch();

    Storage::disk('local')->assertMissing($files);
    Storage::disk('local')->assertMissing(["chunks/{$id}", "audio/{$id}"]);
});

it('keeps the files of uploads that still exist', function () {
    $upload = Upload::factory()->uploaded()->create();
    $files = [
        "chunks/{$upload->id}/0",
        $upload->path,
        "{$upload->audioDirectory()}/0000.wav",
        $upload->transcriptPath(),
        $upload->translationPath(),
        $upload->subtitlePath('srt'),
    ];
    foreach ($files as $file) {
        Storage::disk('local')->put($file, 'x');
    }

    DeleteOrphanedFiles::dispatch();

    Storage::disk('local')->assertExists($files);
});

it('does nothing when there are no files yet', function () {
    DeleteOrphanedFiles::dispatch();

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('runs hourly', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === DeleteOrphanedFiles::class);

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('0 * * * *');
});
