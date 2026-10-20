<?php

namespace App\Services;

use App\Enums\UploadStatus;
use App\Models\Upload;
use finfo;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ChunkedUploadService
{
    /**
     * Start a chunked upload for a file the client is about to send.
     *
     * @param  array{name: string, size: int, mime_type?: string|null, language?: string|null, subtitle_format: string}  $attributes
     */
    public function start(array $attributes): Upload
    {
        $chunkSize = (int) config('uploads.chunk_size');

        return Upload::create([
            'original_name' => $attributes['name'],
            'extension' => strtolower(pathinfo($attributes['name'], PATHINFO_EXTENSION)),
            'mime_type' => $attributes['mime_type'] ?? null,
            'size' => $attributes['size'],
            'chunk_size' => $chunkSize,
            'total_chunks' => (int) ceil($attributes['size'] / $chunkSize),
            'language' => $attributes['language'] ?? null,
            'subtitle_format' => $attributes['subtitle_format'],
            'status' => UploadStatus::Uploading,
        ]);
    }

    /**
     * Store one chunk. Re-sending an index overwrites it, so clients can retry safely.
     */
    public function storeChunk(Upload $upload, int $index, UploadedFile $chunk): void
    {
        $this->disk()->putFileAs($this->chunkDirectory($upload), $chunk, (string) $index);
    }

    /**
     * Join every chunk into the final media file and mark the upload complete.
     *
     * @throws ValidationException
     */
    public function complete(Upload $upload): Upload
    {
        $missing = $this->missingChunkIndexes($upload);

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'chunks' => 'Missing chunks: '.implode(', ', array_slice($missing, 0, 20)).'.',
            ]);
        }

        $path = "uploads/{$upload->id}.{$upload->extension}";
        $this->assemble($upload, $path);

        $disk = $this->disk();

        if ($disk->size($path) !== $upload->size) {
            $disk->delete($path);

            throw ValidationException::withMessages([
                'chunks' => 'The assembled file size does not match the declared size.',
            ]);
        }

        $mimeType = $this->detectMimeType($disk->path($path));

        if (! $this->isMediaMimeType($mimeType)) {
            $disk->delete($path);

            throw ValidationException::withMessages([
                'file' => 'The file is not a supported video or audio file.',
            ]);
        }

        $disk->deleteDirectory($this->chunkDirectory($upload));

        $upload->update([
            'path' => $path,
            'mime_type' => $mimeType,
            'status' => UploadStatus::Uploaded,
            'completed_at' => now(),
        ]);

        return $upload;
    }

    /**
     * Delete the upload's chunks and assembled file, if any.
     */
    public function deleteFiles(Upload $upload): void
    {
        $disk = $this->disk();

        $disk->deleteDirectory($this->chunkDirectory($upload));

        if ($upload->path !== null) {
            $disk->delete($upload->path);
        }
    }

    /**
     * @return list<int>
     */
    private function missingChunkIndexes(Upload $upload): array
    {
        $disk = $this->disk();
        $directory = $this->chunkDirectory($upload);
        $missing = [];

        for ($index = 0; $index < $upload->total_chunks; $index++) {
            if (! $disk->exists("{$directory}/{$index}")) {
                $missing[] = $index;
            }
        }

        return $missing;
    }

    /**
     * Stream the chunks in order into one file so memory use stays at one buffer.
     */
    private function assemble(Upload $upload, string $path): void
    {
        $disk = $this->disk();
        $disk->makeDirectory(dirname($path));

        $output = fopen($disk->path($path), 'wb');

        if ($output === false) {
            throw new RuntimeException("Unable to open [{$path}] for writing.");
        }

        try {
            for ($index = 0; $index < $upload->total_chunks; $index++) {
                $input = $disk->readStream("{$this->chunkDirectory($upload)}/{$index}");
                stream_copy_to_stream($input, $output);
                fclose($input);
            }
        } finally {
            fclose($output);
        }
    }

    /**
     * Detect the type from the file's bytes only. The disk's own detector falls
     * back to the extension, which would let a renamed non-media file through.
     */
    private function detectMimeType(string $absolutePath): ?string
    {
        return (new finfo(FILEINFO_MIME_TYPE))->file($absolutePath) ?: null;
    }

    private function isMediaMimeType(?string $mimeType): bool
    {
        return $mimeType !== null
            && (str_starts_with($mimeType, 'video/') || str_starts_with($mimeType, 'audio/'));
    }

    private function chunkDirectory(Upload $upload): string
    {
        return "chunks/{$upload->id}";
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('uploads.disk'));
    }
}
