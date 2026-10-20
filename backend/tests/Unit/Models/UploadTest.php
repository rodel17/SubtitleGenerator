<?php

use App\Enums\UploadStatus;
use App\Models\Upload;

test('expected chunk size is the chunk size for every chunk but the last', function (int $size, int $chunkSize, int $totalChunks, int $index, int $expected) {
    $upload = new Upload([
        'size' => $size,
        'chunk_size' => $chunkSize,
        'total_chunks' => $totalChunks,
    ]);

    expect($upload->expectedChunkSize($index))->toBe($expected);
})->with([
    'first of three' => [45, 20, 3, 0, 20],
    'middle of three' => [45, 20, 3, 1, 20],
    'short last chunk' => [45, 20, 3, 2, 5],
    'full last chunk on exact multiple' => [40, 20, 2, 1, 20],
    'only chunk, smaller than chunk size' => [7, 20, 1, 0, 7],
    'only chunk, exactly chunk size' => [20, 20, 1, 0, 20],
    'one-byte last chunk' => [21, 20, 2, 1, 1],
]);

test('only an upload that is still receiving chunks counts as uploading', function (UploadStatus $status, bool $expected) {
    $upload = new Upload(['status' => $status]);

    expect($upload->isUploading())->toBe($expected);
})->with([
    'uploading' => [UploadStatus::Uploading, true],
    'assembling' => [UploadStatus::Assembling, false],
    'uploaded' => [UploadStatus::Uploaded, false],
    'transcribing' => [UploadStatus::Transcribing, false],
    'ready' => [UploadStatus::Ready, false],
    'failed' => [UploadStatus::Failed, false],
]);

test('"both" generates srt and vtt, any other format just itself', function (string $format, array $expected) {
    expect((new Upload(['subtitle_format' => $format]))->subtitleFormats())->toBe($expected);
})->with([
    'srt' => ['srt', ['srt']],
    'vtt' => ['vtt', ['vtt']],
    'both' => ['both', ['srt', 'vtt']],
]);

test('a new upload starts as uploading', function () {
    expect((new Upload)->isUploading())->toBeTrue();
});
