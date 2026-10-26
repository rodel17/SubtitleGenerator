<?php

namespace App\Jobs;

use App\Enums\UploadStatus;
use App\Exceptions\TranslationException;
use App\Models\Upload;
use App\Services\Translation\TranscriptTranslator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Between transcribing and writing subtitles (75–95% of the progress), only when the
 * user chose a language to translate into: transcript → translated transcript JSON.
 * GenerateSubtitles then writes the translation instead of the spoken language.
 */
class TranslateTranscript implements ShouldQueue
{
    use Queueable;

    /**
     * Retries cover the translator restarting or still downloading its model.
     */
    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120];

    public int $timeout = 1800;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Upload $upload)
    {
        $this->onQueue('transcription');
    }

    public function handle(TranscriptTranslator $translator): void
    {
        $disk = Storage::disk(config('uploads.disk'));
        $transcript = json_decode($disk->get($this->upload->transcriptPath()), true, flags: JSON_THROW_ON_ERROR);

        // Already spoken in that language: the transcript is the translation
        if (! $translator->needsTranslation($transcript, $this->upload->translate_to)) {
            return;
        }

        $this->upload->transitionTo(UploadStatus::Translating);
        $this->upload->reportProgress(75);

        try {
            $translated = $translator->translate(
                $transcript,
                $this->upload->translate_to,
                fn (float $fraction) => $this->upload->reportProgress(75 + (int) floor($fraction * 20)),
            );
        } catch (TranslationException $exception) {
            $this->fail($exception);

            return;
        }

        $disk->put($this->upload->translationPath(), json_encode($translated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function failed(?Throwable $exception): void
    {
        $this->upload->markFailed($exception instanceof TranslationException
            ? $exception->getMessage()
            : 'Translation failed. Please try again.');
    }
}
