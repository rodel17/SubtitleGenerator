<?php

use App\Enums\UploadStatus;
use App\Models\Upload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    config(['uploads.chunk_size' => 20]);
});

/**
 * Bytes of a tiny but real WAV file, so content-based MIME detection sees audio.
 */
function wavBytes(int $sampleCount = 30): string
{
    return 'RIFF'.pack('V', 36 + $sampleCount).'WAVE'
        .'fmt '.pack('V', 16).pack('v', 1).pack('v', 1).pack('V', 8000).pack('V', 8000).pack('v', 1).pack('v', 8)
        .'data'.pack('V', $sampleCount).str_repeat("\x80", $sampleCount);
}

/**
 * Start an upload for the given bytes and return its id.
 */
function startUpload(string $bytes, string $name = 'interview.wav'): string
{
    return test()->postJson('/api/uploads', [
        'name' => $name,
        'size' => strlen($bytes),
        'mime_type' => 'audio/wav',
        'language' => 'en',
        'subtitle_format' => 'srt',
    ])->assertCreated()->json('data.id');
}

/**
 * Send every chunk of the given bytes for an upload.
 */
function sendChunks(string $uploadId, string $bytes, int $chunkSize = 20): void
{
    foreach (str_split($bytes, $chunkSize) as $index => $chunk) {
        test()->post("/api/uploads/{$uploadId}/chunks", [
            'index' => $index,
            'chunk' => UploadedFile::fake()->createWithContent('chunk', $chunk),
        ], ['Accept' => 'application/json'])->assertNoContent();
    }
}

test('starting an upload returns how to split the file', function () {
    $response = $this->postJson('/api/uploads', [
        'name' => 'Team Interview.MP4',
        'size' => 45,
        'language' => 'en',
        'subtitle_format' => 'vtt',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Team Interview.MP4')
        ->assertJsonPath('data.extension', 'mp4')
        ->assertJsonPath('data.chunk_size', 20)
        ->assertJsonPath('data.total_chunks', 3)
        ->assertJsonPath('data.status', 'uploading');
});

test('starting an upload rejects unsupported extensions and oversized files', function () {
    config(['uploads.max_size' => 100]);

    $this->postJson('/api/uploads', [
        'name' => 'notes.txt',
        'size' => 101,
        'subtitle_format' => 'srt',
    ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'size']);

    expect(Upload::count())->toBe(0);
});

test('a full chunked upload is assembled into the original file', function () {
    $bytes = wavBytes();
    $uploadId = startUpload($bytes);

    sendChunks($uploadId, $bytes);

    $this->postJson("/api/uploads/{$uploadId}/completion")
        ->assertOk()
        ->assertJsonPath('data.status', 'uploaded')
        ->assertJsonPath('data.mime_type', 'audio/x-wav');

    $upload = Upload::findOrFail($uploadId);

    expect($upload->status)->toBe(UploadStatus::Uploaded)
        ->and($upload->completed_at)->not->toBeNull()
        ->and(Storage::disk('local')->get($upload->path))->toBe($bytes);

    Storage::disk('local')->assertDirectoryEmpty("chunks/{$uploadId}");
});

test('resending a chunk replaces it instead of duplicating it', function () {
    $bytes = wavBytes();
    $uploadId = startUpload($bytes);

    sendChunks($uploadId, $bytes);
    sendChunks($uploadId, substr($bytes, 0, 20));

    $this->postJson("/api/uploads/{$uploadId}/completion")->assertOk();

    expect(Storage::disk('local')->get(Upload::findOrFail($uploadId)->path))->toBe($bytes);
});

test('a chunk with the wrong size or index is rejected', function () {
    $uploadId = startUpload(wavBytes());

    $this->post("/api/uploads/{$uploadId}/chunks", [
        'index' => 0,
        'chunk' => UploadedFile::fake()->createWithContent('chunk', 'too short'),
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('chunk');

    $this->post("/api/uploads/{$uploadId}/chunks", [
        'index' => 99,
        'chunk' => UploadedFile::fake()->createWithContent('chunk', str_repeat('a', 20)),
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('index');
});

test('completing with missing chunks fails and keeps the upload open', function () {
    $bytes = wavBytes();
    $uploadId = startUpload($bytes);

    sendChunks($uploadId, substr($bytes, 0, 20));

    $this->postJson("/api/uploads/{$uploadId}/completion")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('chunks');

    expect(Upload::findOrFail($uploadId)->status)->toBe(UploadStatus::Uploading);
});

test('a non-media file renamed to a media extension is rejected on completion', function () {
    $bytes = str_repeat('plain text, not a video. ', 3);
    $uploadId = startUpload($bytes, 'fake.mp4');

    sendChunks($uploadId, $bytes);

    $this->postJson("/api/uploads/{$uploadId}/completion")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    Storage::disk('local')->assertMissing("uploads/{$uploadId}.mp4");
});

test('an MPEG transport stream (.ts) video is accepted', function () {
    $packet = "\x47\x40\x00\x10".str_repeat("\xff", 184);
    $bytes = str_repeat($packet, 10);
    $uploadId = startUpload($bytes, 'broadcast.ts');

    sendChunks($uploadId, $bytes);

    $this->postJson("/api/uploads/{$uploadId}/completion")
        ->assertOk()
        ->assertJsonPath('data.mime_type', 'video/MP2T');
});

test('a TypeScript source file named .ts is rejected on completion', function () {
    $bytes = str_repeat("export const answer: number = 42;\n", 3);
    $uploadId = startUpload($bytes, 'app.ts');

    sendChunks($uploadId, $bytes);

    $this->postJson("/api/uploads/{$uploadId}/completion")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');
});

test('a completed upload accepts no more chunks', function () {
    $upload = Upload::factory()->uploaded()->create(['size' => 20, 'chunk_size' => 20, 'total_chunks' => 1]);

    $this->post("/api/uploads/{$upload->id}/chunks", [
        'index' => 0,
        'chunk' => UploadedFile::fake()->createWithContent('chunk', str_repeat('a', 20)),
    ], ['Accept' => 'application/json'])->assertConflict();

    $this->postJson("/api/uploads/{$upload->id}/completion")->assertConflict();
});

test('deleting an upload removes its record and files', function () {
    $bytes = wavBytes();
    $uploadId = startUpload($bytes);
    sendChunks($uploadId, $bytes);
    $this->postJson("/api/uploads/{$uploadId}/completion")->assertOk();
    $path = Upload::findOrFail($uploadId)->path;

    $this->deleteJson("/api/uploads/{$uploadId}")->assertNoContent();

    expect(Upload::find($uploadId))->toBeNull();
    Storage::disk('local')->assertMissing($path);
});

test('recent uploads are listed newest first', function () {
    $older = Upload::factory()->uploaded()->create(['created_at' => now()->subHour()]);
    $newer = Upload::factory()->create();

    $this->getJson('/api/uploads')
        ->assertOk()
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.1.id', $older->id);
});

test('abandoned incomplete uploads are pruned with their chunks', function () {
    $abandoned = Upload::factory()->create(['created_at' => now()->subDays(2)]);
    $recent = Upload::factory()->create();
    $finished = Upload::factory()->uploaded()->create(['created_at' => now()->subDays(2)]);
    Storage::disk('local')->put("chunks/{$abandoned->id}/0", 'data');

    $this->artisan('model:prune', ['--model' => [Upload::class]])->assertSuccessful();

    expect(Upload::find($abandoned->id))->toBeNull()
        ->and(Upload::find($recent->id))->not->toBeNull()
        ->and(Upload::find($finished->id))->not->toBeNull();
    Storage::disk('local')->assertMissing("chunks/{$abandoned->id}/0");
});
