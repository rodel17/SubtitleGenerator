<?php

namespace App\Jobs;

use App\Enums\EmailStatus;
use App\Models\Upload;
use App\Services\ChunkedUploadService;
use App\Services\Transcription\CueBuilder;
use App\Services\Transcription\SubtitleWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Last step of transcription (95–100% of its progress): transcript → SRT / VTT files.
 */
class GenerateSubtitles implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Upload $upload)
    {
        $this->onQueue('transcription');
    }

    public function handle(CueBuilder $cues, SubtitleWriter $writer, ChunkedUploadService $uploads): void
    {
        $disk = Storage::disk(config('uploads.disk'));
        $this->upload->reportProgress(95);

        $segments = json_decode($disk->get($this->upload->transcriptPath()), true, flags: JSON_THROW_ON_ERROR)['segments'];
        $built = $cues->build($segments);

        foreach ($this->upload->subtitleFormats() as $format) {
            $disk->put($this->upload->subtitlePath($format), $writer->write($built, $format));
        }

        // Only the subtitle files are kept, until the user downloads them (or they are emailed)
        $uploads->deleteWorkingFiles($this->upload);

        // Emailing was chosen before uploading: mark it as sending first, so the page
        // never offers to download subtitles that are already on their way
        if ($this->upload->email_when_ready) {
            $this->upload->update(['email_status' => EmailStatus::Sending]);
        }

        $this->upload->markReady();

        if ($this->upload->email_when_ready) {
            SendSubtitlesEmail::dispatch($this->upload);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->upload->markFailed('The subtitle files could not be created. Please try again.');
    }
}
