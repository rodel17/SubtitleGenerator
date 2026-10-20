<?php

use App\Enums\UploadStatus;
use App\Models\Upload;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

it('downloads the subtitle file named after the original media', function (string $format, string $contentType) {
    $upload = Upload::factory()->ready()->create(['original_name' => 'Team Interview.mp4', 'subtitle_format' => 'both']);
    Storage::disk('local')->put($upload->subtitlePath($format), "subtitle content\n");

    $response = $this->get("/api/uploads/{$upload->id}/subtitles/{$format}");

    $response->assertOk()
        ->assertDownload("Team Interview.{$format}")
        ->assertHeader('Content-Type', $contentType);
    expect($response->streamedContent())->toBe("subtitle content\n");
})->with([
    'srt' => ['srt', 'application/x-subrip; charset=UTF-8'],
    'vtt' => ['vtt', 'text/vtt; charset=UTF-8'],
]);

it('returns 404 until the upload is ready', function (UploadStatus $status) {
    $upload = Upload::factory()->uploaded()->create(['status' => $status]);
    Storage::disk('local')->put($upload->subtitlePath('srt'), 'x');

    $this->get("/api/uploads/{$upload->id}/subtitles/srt")->assertNotFound();
})->with([
    'uploading' => UploadStatus::Uploading,
    'assembling' => UploadStatus::Assembling,
    'uploaded' => UploadStatus::Uploaded,
    'transcribing' => UploadStatus::Transcribing,
    'failed' => UploadStatus::Failed,
]);

it('returns 404 for a format that was not requested', function () {
    $upload = Upload::factory()->ready()->create(['subtitle_format' => 'srt']);
    Storage::disk('local')->put($upload->subtitlePath('vtt'), 'x');

    $this->get("/api/uploads/{$upload->id}/subtitles/vtt")->assertNotFound();
});

it('returns 404 when the file is missing from storage', function () {
    $upload = Upload::factory()->ready()->create(['subtitle_format' => 'srt']);

    $this->get("/api/uploads/{$upload->id}/subtitles/srt")->assertNotFound();
});

it('only serves srt and vtt', function (string $format) {
    $upload = Upload::factory()->ready()->create(['subtitle_format' => 'srt']);
    Storage::disk('local')->put($upload->subtitlePath('srt'), 'x');

    $this->get("/api/uploads/{$upload->id}/subtitles/{$format}")->assertNotFound();
})->with(['ass', 'srt.php', '..%2F..%2F.env']);
