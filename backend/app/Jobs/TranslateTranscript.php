<?php

namespace App\Jobs;

use App\Enums\UploadStatus;
use App\Exceptions\TranslationException;
use App\Exceptions\TranslatorUnavailableException;
use App\Models\Upload;
use App\Services\Translation\TranscriptTranslator;
use App\Services\Translation\TranslationEngines;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Between transcribing and writing subtitles (75–95% of the progress), only when the
 * user chose a language to translate into: transcript → translated transcript JSON.
 * GenerateSubtitles then writes the translation instead of the spoken language.
 * Uses the engine the user chose; when Enhanced (Azure) can't be used, Standard
 * (local) translates instead and `translated_with` says so.
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

    public function handle(TranscriptTranslator $translator, TranslationEngines $engines): void
    {
        $disk = Storage::disk(config('uploads.disk'));
        $transcript = json_decode($disk->get($this->upload->transcriptPath()), true, flags: JSON_THROW_ON_ERROR);

        // Already spoken in that language: the transcript is the translation
        if (! $translator->needsTranslation($transcript, $this->upload->translate_to)) {
            return;
        }

        $this->upload->transitionTo(UploadStatus::Translating);
        $this->upload->reportProgress(75);

        $engine = $this->upload->translation_engine === TranslationEngines::AZURE && $engines->isAvailable(TranslationEngines::AZURE)
            ? TranslationEngines::AZURE
            : TranslationEngines::LOCAL;

        try {
            try {
                $translated = $this->translate($translator, $engines, $engine, $transcript);
            } catch (TranslatorUnavailableException $exception) {
                // Quota used up or an outage: translate on our own server instead
                report($exception);
                $engine = TranslationEngines::LOCAL;
                $this->upload->reportProgress(75);
                $translated = $this->translate($translator, $engines, $engine, $transcript);
            }
        } catch (TranslationException $exception) {
            $this->fail($exception);

            return;
        }

        $disk->put($this->upload->translationPath(), json_encode($translated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->upload->update(['translated_with' => $engine]);
    }

    /**
     * @param  array{language?: string|null, segments: list<array{start: float, end: float, text: string}>}  $transcript
     * @return array{language: string, segments: list<array<string, mixed>>}
     */
    private function translate(TranscriptTranslator $translator, TranslationEngines $engines, string $engine, array $transcript): array
    {
        return $translator->using($engines->translator($engine))->translate(
            $transcript,
            $this->upload->translate_to,
            fn (float $fraction) => $this->upload->reportProgress(75 + (int) floor($fraction * 20)),
        );
    }

    public function failed(?Throwable $exception): void
    {
        $this->upload->markFailed($exception instanceof TranslationException
            ? $exception->getMessage()
            : 'Translation failed. Please try again.');
    }
}
