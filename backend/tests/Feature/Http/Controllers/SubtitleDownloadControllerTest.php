<?php

use App\Enums\UploadStatus;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    Storage::fake('local');
});

it('downloads the one subtitle file, named after the original media', function (string $format, string $contentType) {
    $upload = Upload::factory()->for($this->user)->ready()->create(['original_name' => 'Team Interview.mp4', 'subtitle_format' => $format]);
    Storage::disk('local')->put($upload->subtitlePath($format), "subtitle content\n");

    $response = $this->get("/api/uploads/{$upload->id}/subtitles");

    $response->assertOk()
        ->assertDownload("Team Interview.{$format}")
        ->assertHeader('Content-Type', $contentType);
    expect($response->streamedContent())->toBe("subtitle content\n");
})->with([
    'srt' => ['srt', 'application/x-subrip; charset=UTF-8'],
    'vtt' => ['vtt', 'text/vtt; charset=UTF-8'],
]);

it('downloads both files in one zip when both formats were requested', function () {
    $upload = Upload::factory()->for($this->user)->ready()->create(['original_name' => 'Team Interview.mp4', 'subtitle_format' => 'both']);
    Storage::disk('local')->put($upload->subtitlePath('srt'), "srt content\n");
    Storage::disk('local')->put($upload->subtitlePath('vtt'), "WEBVTT\n");

    $response = $this->get("/api/uploads/{$upload->id}/subtitles");

    $response->assertOk()
        ->assertDownload('Team Interview.zip')
        ->assertHeader('Content-Type', 'application/zip');

    $zip = new ZipArchive;
    $zip->open($response->baseResponse->getFile()->getPathname());
    expect($zip->numFiles)->toBe(2);
    expect($zip->getFromName('Team Interview.srt'))->toBe("srt content\n");
    expect($zip->getFromName('Team Interview.vtt'))->toBe("WEBVTT\n");
    $zip->close();
});

it('names translated subtitles with the language they were translated into', function (string $format, string $name) {
    $upload = Upload::factory()->for($this->user)->ready()->create(['original_name' => 'Team Interview.mp4', 'subtitle_format' => $format, 'translate_to' => 'fil']);
    Storage::disk('local')->put($upload->subtitlePath('srt'), 'x');
    Storage::disk('local')->put($upload->subtitlePath('vtt'), 'x');

    $this->get("/api/uploads/{$upload->id}/subtitles")->assertOk()->assertDownload($name);
})->with([
    'one format' => ['srt', 'Team Interview.fil.srt'],
    'both formats' => ['both', 'Team Interview.fil.zip'],
]);

it('returns 404 until the upload is ready', function (UploadStatus $status) {
    $upload = Upload::factory()->for($this->user)->uploaded()->create(['status' => $status]);
    Storage::disk('local')->put($upload->subtitlePath('srt'), 'x');

    $this->get("/api/uploads/{$upload->id}/subtitles")->assertNotFound();
})->with([
    'uploading' => UploadStatus::Uploading,
    'assembling' => UploadStatus::Assembling,
    'uploaded' => UploadStatus::Uploaded,
    'transcribing' => UploadStatus::Transcribing,
    'failed' => UploadStatus::Failed,
]);

it('returns 404 when a requested file is missing from storage', function () {
    $upload = Upload::factory()->for($this->user)->ready()->create(['subtitle_format' => 'both']);
    Storage::disk('local')->put($upload->subtitlePath('srt'), 'x');

    $this->get("/api/uploads/{$upload->id}/subtitles")->assertNotFound();
});

it("returns 404 for another user's subtitles", function () {
    $upload = Upload::factory()->ready()->create(['subtitle_format' => 'srt']);
    Storage::disk('local')->put($upload->subtitlePath('srt'), 'x');

    $this->get("/api/uploads/{$upload->id}/subtitles")->assertNotFound();
});

it('leaves the files in place: the client deletes the upload once it has them', function () {
    $upload = Upload::factory()->for($this->user)->ready()->create(['subtitle_format' => 'srt']);
    Storage::disk('local')->put($upload->subtitlePath('srt'), 'x');

    $this->get("/api/uploads/{$upload->id}/subtitles")->assertOk();

    $this->assertModelExists($upload);
    Storage::disk('local')->assertExists($upload->subtitlePath('srt'));
});
