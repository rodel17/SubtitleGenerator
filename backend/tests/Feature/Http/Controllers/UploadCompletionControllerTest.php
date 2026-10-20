<?php

use App\Enums\UploadStatus;
use App\Models\Upload;
use Illuminate\Support\Facades\Storage;

it('assembles the chunks into the original file and marks the upload uploaded', function () {
    Storage::fake('local');
    $this->travelTo('2026-10-20 12:00:00');
    $bytes = wavBytes();
    $upload = Upload::factory()->forContent($bytes, 'interview.wav')->create();
    sendAllChunks($upload, $bytes);

    $response = $this->postJson("/api/uploads/{$upload->id}/completion");

    $response->assertOk()
        ->assertJsonPath('data.status', 'uploaded')
        ->assertJsonPath('data.mime_type', 'audio/x-wav')
        ->assertJsonPath('data.completed_at', '2026-10-20T12:00:00.000000Z');
    $upload->refresh();
    expect($upload->status)->toBe(UploadStatus::Uploaded);
    expect($upload->path)->toBe("uploads/{$upload->id}.wav");
    expect(Storage::disk('local')->get($upload->path))->toBe($bytes);
});

it('deletes the chunks once the file is assembled', function () {
    Storage::fake('local');
    $bytes = wavBytes();
    $upload = Upload::factory()->forContent($bytes)->create();
    sendAllChunks($upload, $bytes);

    $this->postJson("/api/uploads/{$upload->id}/completion")->assertOk();

    Storage::disk('local')->assertMissing("chunks/{$upload->id}");
});

it('assembles chunks in numeric order when there are more than ten', function () {
    Storage::fake('local');
    $bytes = wavBytes();
    $upload = Upload::factory()->forContent($bytes, chunkSize: 5)->create();
    sendAllChunks($upload, $bytes);

    $this->postJson("/api/uploads/{$upload->id}/completion")->assertOk();

    expect($upload->total_chunks)->toBe(15);
    expect(Storage::disk('local')->get($upload->fresh()->path))->toBe($bytes);
});

it('assembles the original file when chunks arrive out of order', function () {
    Storage::fake('local');
    $bytes = wavBytes();
    $upload = Upload::factory()->forContent($bytes)->create();
    sendAllChunks($upload, $bytes, order: [3, 0, 2, 1]);

    $this->postJson("/api/uploads/{$upload->id}/completion")->assertOk();

    expect(Storage::disk('local')->get($upload->fresh()->path))->toBe($bytes);
});

it('accepts each kind of media by its content', function (string $bytes, string $name, string $mimeType) {
    Storage::fake('local');
    $upload = Upload::factory()->forContent($bytes, $name)->create();
    sendAllChunks($upload, $bytes);

    $response = $this->postJson("/api/uploads/{$upload->id}/completion");

    $response->assertOk()->assertJsonPath('data.mime_type', $mimeType);
})->with([
    'wav audio' => [fn () => wavBytes(), 'clip.wav', 'audio/x-wav'],
    'mpeg transport stream' => [fn () => mpegTsBytes(), 'broadcast.ts', 'video/MP2T'],
]);

it('stores the file under its id, whatever the original name contains', function () {
    Storage::fake('local');
    $bytes = mpegTsBytes();
    $upload = Upload::factory()->forContent($bytes, '../../public/evil.ts')->create();
    sendAllChunks($upload, $bytes);

    $this->postJson("/api/uploads/{$upload->id}/completion")->assertOk();

    expect($upload->fresh()->path)->toBe("uploads/{$upload->id}.ts");
    expect(Storage::disk('local')->allFiles())->toBe(["uploads/{$upload->id}.ts"]);
});

it('returns 422 listing the missing chunks and keeps the upload open', function () {
    Storage::fake('local');
    $bytes = wavBytes();
    $upload = Upload::factory()->forContent($bytes)->create();
    sendAllChunks($upload, $bytes, order: [0, 2]);

    $response = $this->postJson("/api/uploads/{$upload->id}/completion");

    $response->assertUnprocessable()->assertJsonValidationErrors(['chunks' => 'Missing chunks: 1, 3.']);
    expect($upload->fresh()->isUploading())->toBeTrue();
    Storage::disk('local')->assertMissing("uploads/{$upload->id}.wav");
});

it('lists at most 20 missing chunks in the error', function () {
    Storage::fake('local');
    $upload = Upload::factory()->forContent(str_repeat('a', 25), chunkSize: 1)->create();

    $response = $this->postJson("/api/uploads/{$upload->id}/completion");

    $response->assertUnprocessable()->assertJsonValidationErrors([
        'chunks' => 'Missing chunks: 0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19.',
    ]);
});

it('returns 422 when a stored chunk was corrupted after upload', function () {
    Storage::fake('local');
    $bytes = wavBytes();
    $upload = Upload::factory()->forContent($bytes)->create();
    sendAllChunks($upload, $bytes);
    Storage::disk('local')->put("chunks/{$upload->id}/1", 'short');

    $response = $this->postJson("/api/uploads/{$upload->id}/completion");

    $response->assertUnprocessable()->assertJsonValidationErrors([
        'chunks' => 'The assembled file size does not match the declared size.',
    ]);
    expect($upload->fresh()->isUploading())->toBeTrue();
    Storage::disk('local')->assertMissing("uploads/{$upload->id}.wav");
});

it('returns 422 and discards the assembled file when the content is not media', function (string $bytes, string $name) {
    Storage::fake('local');
    $upload = Upload::factory()->forContent($bytes, $name)->create();
    sendAllChunks($upload, $bytes);

    $response = $this->postJson("/api/uploads/{$upload->id}/completion");

    $response->assertUnprocessable()->assertJsonValidationErrors([
        'file' => 'The file is not a supported video or audio file.',
    ]);
    expect($upload->fresh()->isUploading())->toBeTrue();
    Storage::disk('local')->assertMissing("uploads/{$upload->id}.{$upload->extension}");
})->with([
    'plain text renamed to mp4' => [str_repeat('plain text, not a video. ', 3), 'fake.mp4'],
    'typescript source named .ts' => [str_repeat("export const answer: number = 42;\n", 3), 'app.ts'],
    'php script renamed to mov' => ["<?php echo 'hello'; ?>\n<?php echo 'again'; ?>\n", 'shell.mov'],
    'png image renamed to mp3' => ["\x89PNG\r\n\x1a\n".str_repeat("\x00", 40), 'cover.mp3'],
]);

it('returns 409 for an upload that is already complete', function () {
    Storage::fake('local');
    $upload = Upload::factory()->uploaded()->create();

    $response = $this->postJson("/api/uploads/{$upload->id}/completion");

    $response->assertConflict()->assertJsonPath('message', 'This upload is already complete.');
});

it('returns 409 when completion is requested twice', function () {
    Storage::fake('local');
    $bytes = wavBytes();
    $upload = Upload::factory()->forContent($bytes)->create();
    sendAllChunks($upload, $bytes);
    $this->postJson("/api/uploads/{$upload->id}/completion")->assertOk();

    $response = $this->postJson("/api/uploads/{$upload->id}/completion");

    $response->assertConflict();
    expect(Storage::disk('local')->get($upload->fresh()->path))->toBe($bytes);
});

it('returns 404 for an unknown upload', function () {
    $this->postJson('/api/uploads/01a15979-96db-7264-a6ec-88c51a111e09/completion')->assertNotFound();
});
