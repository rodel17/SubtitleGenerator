<?php

namespace App\Jobs;

use App\Models\Upload;
use App\Services\ChunkedUploadService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * Join the received chunks into the media file, then queue the transcription chain.
 */
class AssembleUpload implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Assembly only fails on disk errors, which a retry will not fix.
     */
    public int $tries = 1;

    /**
     * Copying 2 GB on a slow (bind-mounted) disk can take minutes.
     */
    public int $timeout = 900;

    /**
     * The upload was deleted (cancelled) before the job ran.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Upload $upload)
    {
        $this->onQueue('uploads');
    }

    public function uniqueId(): string
    {
        return $this->upload->id;
    }

    public function handle(ChunkedUploadService $uploads): void
    {
        $uploads->assemble($this->upload, fn (int $percent) => $this->upload->reportProgress($percent));

        Bus::chain([
            new ExtractAudio($this->upload),
            new TranscribeAudio($this->upload),
            new GenerateSubtitles($this->upload),
        ])->dispatch();
    }

    public function failed(?Throwable $exception): void
    {
        $this->upload->markFailed('The file could not be put together. Please upload it again.');
    }
}
