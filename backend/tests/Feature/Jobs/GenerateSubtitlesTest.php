<?php

use App\Enums\UploadStatus;
use App\Jobs\GenerateSubtitles;
use App\Models\Upload;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

function putTranscript(Upload $upload): void
{
    Storage::disk('local')->put($upload->transcriptPath(), json_encode([
        'language' => 'en',
        'segments' => [['start' => 1.5, 'end' => 3.25, 'text' => 'Hello there.']],
    ]));
}

it('writes the chosen format and marks the upload ready', function (string $format, string $expected) {
    $upload = Upload::factory()->uploaded()->create(['status' => UploadStatus::Transcribing, 'subtitle_format' => $format]);
    putTranscript($upload);

    GenerateSubtitles::dispatch($upload);

    expect(Storage::disk('local')->get($upload->subtitlePath($format)))->toBe($expected);
    expect($upload->fresh()->status)->toBe(UploadStatus::Ready);
    expect($upload->fresh()->progress())->toBeNull();
})->with([
    'srt' => ['srt', "1\n00:00:01,500 --> 00:00:03,250\nHello there.\n"],
    'vtt' => ['vtt', "WEBVTT\n\n00:00:01.500 --> 00:00:03.250\nHello there.\n"],
]);

it('writes both files when both formats were requested', function () {
    $upload = Upload::factory()->uploaded()->create(['status' => UploadStatus::Transcribing, 'subtitle_format' => 'both']);
    putTranscript($upload);

    GenerateSubtitles::dispatch($upload);

    Storage::disk('local')->assertExists([$upload->subtitlePath('srt'), $upload->subtitlePath('vtt')]);
});

it('only writes the requested format', function () {
    $upload = Upload::factory()->uploaded()->create(['status' => UploadStatus::Transcribing, 'subtitle_format' => 'srt']);
    putTranscript($upload);

    GenerateSubtitles::dispatch($upload);

    Storage::disk('local')->assertMissing($upload->subtitlePath('vtt'));
});

it('deletes the media, audio pieces and transcript, keeping only the subtitles', function () {
    $upload = Upload::factory()->uploaded()->create(['status' => UploadStatus::Transcribing, 'subtitle_format' => 'srt']);
    putTranscript($upload);
    Storage::disk('local')->put($upload->path, 'media');
    Storage::disk('local')->put("{$upload->audioDirectory()}/0000.wav", wavBytes());
    $mediaPath = $upload->path;

    GenerateSubtitles::dispatch($upload);

    Storage::disk('local')->assertMissing([$mediaPath, $upload->transcriptPath()]);
    Storage::disk('local')->assertMissing($upload->audioDirectory());
    Storage::disk('local')->assertExists($upload->subtitlePath('srt'));
    expect($upload->fresh()->path)->toBeNull();
});

it('starts the clock for deleting the subtitles if nobody downloads them', function () {
    $this->freezeTime();
    $upload = Upload::factory()->uploaded()->create(['status' => UploadStatus::Transcribing]);
    putTranscript($upload);

    GenerateSubtitles::dispatch($upload);

    expect($upload->fresh()->finished_at->toDateTimeString())->toBe(now()->toDateTimeString());
});

it('marks the upload failed and deletes its files when the transcript is missing', function () {
    $upload = Upload::factory()->uploaded()->create(['status' => UploadStatus::Transcribing]);
    Storage::disk('local')->put($upload->path, 'media');
    Storage::disk('local')->put("{$upload->audioDirectory()}/0000.wav", wavBytes());
    $mediaPath = $upload->path;

    expect(fn () => GenerateSubtitles::dispatch($upload))->toThrow(Exception::class);

    $upload->refresh();
    expect($upload->status)->toBe(UploadStatus::Failed);
    expect($upload->error)->toBe('The subtitle files could not be created. Please try again.');
    expect($upload->path)->toBeNull();
    expect($upload->finished_at)->not->toBeNull();
    Storage::disk('local')->assertMissing($mediaPath);
    Storage::disk('local')->assertMissing($upload->audioDirectory());
});
