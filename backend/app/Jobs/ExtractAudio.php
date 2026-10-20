<?php

namespace App\Jobs;

use App\Enums\UploadStatus;
use App\Exceptions\TranscriptionException;
use App\Models\Upload;
use App\Services\Transcription\AudioExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * First step of transcription (0–10% of its progress): media → 16 kHz mono WAV pieces.
 */
class ExtractAudio implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Upload $upload)
    {
        $this->onQueue('transcription');
    }

    public function handle(AudioExtractor $audio): void
    {
        $this->upload->transitionTo(UploadStatus::Transcribing);

        $audio->extract($this->upload, fn (float $fraction) => $this->upload->reportProgress((int) floor($fraction * 10)));
    }

    public function failed(?Throwable $exception): void
    {
        $this->upload->markFailed($exception instanceof TranscriptionException
            ? $exception->getMessage()
            : 'The audio could not be extracted. Please try again.');
    }
}
