<?php

namespace App\Jobs;

use App\Exceptions\TranscriptionException;
use App\Models\Upload;
use App\Services\Transcription\AudioExtractor;
use App\Services\Transcription\Transcriber;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Second step of transcription (10–95% of its progress): send each audio piece
 * to Whisper and save the combined transcript as JSON.
 */
class TranscribeAudio implements ShouldQueue
{
    use Queueable;

    /**
     * Retries cover the Whisper service restarting or loading its model.
     */
    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120];

    public int $timeout = 3600;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Upload $upload)
    {
        $this->onQueue('transcription');
    }

    public function handle(Transcriber $transcriber, AudioExtractor $audio): void
    {
        $disk = Storage::disk(config('uploads.disk'));
        $pieces = $audio->pieces($this->upload);
        $language = $this->upload->language;
        $segments = [];
        $offset = 0.0;

        foreach ($pieces as $number => $piece) {
            // Stopped by the user: the upload and its files are gone, so don't keep Whisper busy
            if (! Upload::query()->whereKey($this->upload->id)->exists()) {
                return;
            }

            $result = $transcriber->transcribe($disk->path($piece), $language);

            foreach ($result['segments'] as $segment) {
                $text = trim($segment['text']);

                if ($text !== '') {
                    $segments[] = [
                        'start' => $segment['start'] + $offset,
                        'end' => $segment['end'] + $offset,
                        'text' => $text,
                        'words' => array_map(fn (array $word): array => [
                            'start' => $word['start'] + $offset,
                            'end' => $word['end'] + $offset,
                            'word' => $word['word'],
                        ], $segment['words'] ?? []),
                    ];
                }
            }

            // Keep later pieces in the language detected in the first one
            if ($language === null || $language === 'auto') {
                $language = $result['language'] ?? $language;
            }

            $offset += $audio->wavDuration($disk->path($piece));
            $this->upload->reportProgress(10 + (int) floor(($number + 1) / count($pieces) * 85));
        }

        if ($segments === []) {
            $this->fail(new TranscriptionException('No speech was detected in this file.'));

            return;
        }

        $disk->put($this->upload->transcriptPath(), json_encode([
            'language' => $language,
            'segments' => $segments,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function failed(?Throwable $exception): void
    {
        $this->upload->markFailed($exception instanceof TranscriptionException
            ? $exception->getMessage()
            : 'Transcription failed. Please try again.');
    }
}
