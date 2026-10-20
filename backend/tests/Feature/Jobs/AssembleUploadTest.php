<?php

use App\Enums\UploadStatus;
use App\Jobs\AssembleUpload;
use App\Jobs\ExtractAudio;
use App\Models\Upload;
use App\Services\ChunkedUploadService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Queue::fake([ExtractAudio::class]);
});

it('reports full progress once every chunk is copied, then clears it with the new status', function () {
    Storage::fake('local');
    $bytes = wavBytes();
    $upload = Upload::factory()->forContent($bytes)->create(['status' => UploadStatus::Assembling]);
    sendAllChunksToDisk($upload, $bytes);
    $reported = [];

    app(ChunkedUploadService::class)->assemble($upload, function (int $percent) use (&$reported) {
        $reported[] = $percent;
    });

    expect($reported)->toBe([25, 50, 75, 100]);
    expect($upload->fresh()->status)->toBe(UploadStatus::Uploaded);
    expect($upload->fresh()->progress())->toBeNull();
});

it('marks the upload failed with a message when the chunks cannot be read', function () {
    Storage::fake('local');
    $upload = Upload::factory()->forContent(wavBytes())->create(['status' => UploadStatus::Assembling]);

    expect(fn () => AssembleUpload::dispatch($upload))->toThrow(RuntimeException::class, 'Chunk 0 of upload');

    $upload->refresh();
    expect($upload->status)->toBe(UploadStatus::Failed);
    expect($upload->error)->toBe('The file could not be put together. Please upload it again.');
    Queue::assertNotPushed(ExtractAudio::class);
});

it('skips the job when the upload was deleted before it ran', function () {
    Storage::fake('local');
    $upload = Upload::factory()->create(['status' => UploadStatus::Assembling]);
    $upload->delete();

    AssembleUpload::dispatch($upload);

    Queue::assertNotPushed(ExtractAudio::class);
    $this->assertDatabaseCount('uploads', 0);
});

/**
 * Put every chunk straight onto the disk, as the chunk endpoint would.
 */
function sendAllChunksToDisk(Upload $upload, string $bytes): void
{
    foreach (str_split($bytes, $upload->chunk_size) as $index => $chunk) {
        Storage::disk('local')->put("chunks/{$upload->id}/{$index}", $chunk);
    }
}
