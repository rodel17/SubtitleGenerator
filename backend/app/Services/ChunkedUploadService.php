<?php

namespace App\Services;

use App\Enums\UploadStatus;
use App\Jobs\AssembleUpload;
use App\Models\Upload;
use App\Models\User;
use finfo;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ChunkedUploadService
{
    /**
     * Bytes read from the start of the file to detect its type. Media signatures
     * sit in the first few kilobytes; libmagic itself reads no more than this.
     */
    private const SNIFF_BYTES = 1024 * 1024;

    /**
     * Start a chunked upload, owned by $user, for a file the client is about to send.
     *
     * @param  array{name: string, size: int, mime_type?: string|null, last_modified?: int|null, language?: string|null, translate_to?: string|null, subtitle_format: string, email_when_ready?: bool}  $attributes
     */
    public function start(User $user, array $attributes): Upload
    {
        $chunkSize = (int) config('uploads.chunk_size');

        return $user->uploads()->create([
            'original_name' => $attributes['name'],
            'extension' => strtolower(pathinfo($attributes['name'], PATHINFO_EXTENSION)),
            'mime_type' => $attributes['mime_type'] ?? null,
            'last_modified' => $attributes['last_modified'] ?? null,
            'size' => $attributes['size'],
            'chunk_size' => $chunkSize,
            'total_chunks' => (int) ceil($attributes['size'] / $chunkSize),
            'language' => $attributes['language'] ?? null,
            'translate_to' => $attributes['translate_to'] ?? null,
            // Only the local engine exists so far; Enhanced (Azure) comes later
            'translation_engine' => isset($attributes['translate_to']) ? 'local' : null,
            'subtitle_format' => $attributes['subtitle_format'],
            'email_when_ready' => (bool) ($attributes['email_when_ready'] ?? false),
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
     * Indexes of the chunks already stored with their expected size. A client
     * resuming an interrupted upload only needs to send the others.
     *
     * @return list<int>
     */
    public function receivedChunkIndexes(Upload $upload): array
    {
        $disk = $this->disk();
        $received = [];

        foreach ($disk->files($this->chunkDirectory($upload)) as $file) {
            $index = basename($file);

            if (! ctype_digit($index) || (int) $index >= $upload->total_chunks) {
                continue;
            }

            if ($disk->size($file) === $upload->expectedChunkSize((int) $index)) {
                $received[] = (int) $index;
            }
        }

        sort($received);

        return $received;
    }

    /**
     * Check that every chunk arrived and the content is media, then queue the
     * (slow) assembly. Everything checked here only reads chunk sizes and the
     * first megabyte, so the request returns quickly even for large files.
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

        if ($this->storedChunkBytes($upload) !== $upload->size) {
            throw ValidationException::withMessages([
                'chunks' => 'The assembled file size does not match the declared size.',
            ]);
        }

        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($this->leadingBytes($upload)) ?: null;

        if (! $this->isMediaMimeType($mimeType)) {
            throw ValidationException::withMessages([
                'file' => 'The file is not a supported video or audio file.',
            ]);
        }

        // Claim the upload atomically so two completion requests cannot both queue it
        $claimed = Upload::query()
            ->whereKey($upload->id)
            ->where('status', UploadStatus::Uploading)
            ->update(['status' => UploadStatus::Assembling, 'mime_type' => $mimeType]);

        abort_if($claimed === 0, 409, 'This upload is already complete.');

        $upload->refresh()->reportProgress(0);
        AssembleUpload::dispatch($upload);

        return $upload;
    }

    /**
     * Join every chunk into the final media file and delete the chunks.
     * Runs in the AssembleUpload job.
     *
     * @param  (callable(int $percent): void)|null  $onProgress
     */
    public function assemble(Upload $upload, ?callable $onProgress = null): void
    {
        $disk = $this->disk();
        $path = "uploads/{$upload->id}.{$upload->extension}";
        $disk->makeDirectory(dirname($path));

        $output = fopen($disk->path($path), 'wb');

        if ($output === false) {
            throw new RuntimeException("Unable to open [{$path}] for writing.");
        }

        try {
            for ($index = 0; $index < $upload->total_chunks; $index++) {
                $input = $disk->readStream("{$this->chunkDirectory($upload)}/{$index}");

                if ($input === null) {
                    throw new RuntimeException("Chunk {$index} of upload [{$upload->id}] is missing.");
                }

                stream_copy_to_stream($input, $output);
                fclose($input);

                if ($onProgress !== null) {
                    $onProgress((int) floor(($index + 1) / $upload->total_chunks * 100));
                }
            }
        } finally {
            fclose($output);
        }

        if ($disk->size($path) !== $upload->size) {
            $disk->delete($path);

            throw new RuntimeException("Assembled file [{$path}] does not match the declared size.");
        }

        $disk->deleteDirectory($this->chunkDirectory($upload));

        $upload->update(['path' => $path, 'completed_at' => now()]);
        $upload->transitionTo(UploadStatus::Uploaded);
    }

    /**
     * Delete everything stored for the upload: chunks, media, audio, transcript, translation and subtitles.
     */
    public function deleteFiles(Upload $upload): void
    {
        $disk = $this->disk();

        $disk->deleteDirectory($this->chunkDirectory($upload));
        $disk->deleteDirectory($upload->audioDirectory());
        $disk->delete([
            $upload->transcriptPath(),
            $upload->translationPath(),
            $upload->subtitlePath('srt'),
            $upload->subtitlePath('vtt'),
        ]);

        if ($upload->path !== null) {
            $disk->delete($upload->path);
        }
    }

    /**
     * Delete everything except the subtitle files: chunks, media, audio, transcript and translation.
     * Runs as soon as the subtitles are written. A null `path` afterwards means the media is gone.
     */
    public function deleteWorkingFiles(Upload $upload): void
    {
        $disk = $this->disk();

        $disk->deleteDirectory($this->chunkDirectory($upload));
        $disk->deleteDirectory($upload->audioDirectory());
        $disk->delete([$upload->transcriptPath(), $upload->translationPath()]);

        if ($upload->path !== null) {
            $disk->delete($upload->path);
            $upload->update(['path' => null]);
        }
    }

    /**
     * Delete files whose upload no longer exists, e.g. written by a job that was
     * still running when the user deleted the upload. Returns how many were deleted.
     */
    public function deleteOrphanedFiles(): int
    {
        $disk = $this->disk();
        $deleted = 0;

        // chunks/{id}/N and audio/{id}/NNNN.wav are directories named after the upload
        foreach (['chunks', 'audio'] as $parent) {
            $directories = collect($disk->directories($parent))->keyBy(fn (string $path): string => basename($path));

            foreach ($this->unknownIds($directories->keys()->all()) as $id) {
                $disk->deleteDirectory($directories[$id]);
                $deleted++;
            }
        }

        // uploads/{id}.ext, transcripts/{id}.json, translations/{id}.json and subtitles/{id}.srt|vtt are files named after it
        foreach (['uploads', 'transcripts', 'translations', 'subtitles'] as $parent) {
            $files = collect($disk->files($parent))->groupBy(fn (string $path): string => pathinfo($path, PATHINFO_FILENAME));

            foreach ($this->unknownIds($files->keys()->all()) as $id) {
                $disk->delete($files[$id]->all());
                $deleted += $files[$id]->count();
            }
        }

        return $deleted;
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function unknownIds(array $ids): array
    {
        $known = Upload::query()->whereKey($ids)->pluck('id')->all();

        return array_values(array_diff($ids, $known));
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

    private function storedChunkBytes(Upload $upload): int
    {
        $disk = $this->disk();
        $total = 0;

        for ($index = 0; $index < $upload->total_chunks; $index++) {
            $total += $disk->size("{$this->chunkDirectory($upload)}/{$index}");
        }

        return $total;
    }

    /**
     * The first SNIFF_BYTES of the file, read across chunks in order. Type
     * detection uses the bytes only: the disk's own detector falls back to the
     * extension, which would let a renamed non-media file through.
     */
    private function leadingBytes(Upload $upload): string
    {
        $disk = $this->disk();
        $bytes = '';

        for ($index = 0; $index < $upload->total_chunks && strlen($bytes) < self::SNIFF_BYTES; $index++) {
            $input = $disk->readStream("{$this->chunkDirectory($upload)}/{$index}");
            $bytes .= stream_get_contents($input, self::SNIFF_BYTES - strlen($bytes));
            fclose($input);
        }

        return $bytes;
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
