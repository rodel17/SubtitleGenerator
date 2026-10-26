<?php

use App\Enums\UploadStatus;
use App\Jobs\TranslateTranscript;
use App\Models\Upload;
use App\Services\Translation\Translator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

function transcribedUpload(?string $spoken = 'en', string $translateTo = 'fil'): Upload
{
    $upload = Upload::factory()->uploaded()->create(['status' => UploadStatus::Transcribing, 'translate_to' => $translateTo, 'translation_engine' => 'local']);
    Storage::disk('local')->put($upload->transcriptPath(), json_encode([
        'language' => $spoken,
        'segments' => [['start' => 1.0, 'end' => 3.0, 'text' => ' Good morning.', 'words' => []]],
    ]));

    return $upload;
}

function translatorAnswering(Closure $answer): void
{
    app()->instance(Translator::class, new class($answer) implements Translator
    {
        public function __construct(private Closure $answer) {}

        public function translate(array $texts, string $from, string $to): array
        {
            return ($this->answer)($texts, $from, $to);
        }
    });
}

it('writes the translated transcript and shows the upload as translating', function () {
    translatorAnswering(fn (array $texts) => ['Magandang umaga.']);
    $upload = transcribedUpload();

    TranslateTranscript::dispatch($upload);

    $translation = json_decode(Storage::disk('local')->get($upload->translationPath()), true);
    expect($translation['language'])->toBe('fil');
    expect($translation['segments'][0])->toMatchArray(['start' => 1.0, 'end' => 3.0, 'text' => 'Magandang umaga.']);
    expect($upload->fresh()->status)->toBe(UploadStatus::Translating);
    expect($upload->fresh()->progress())->toBe(95);
});

it('skips translating when the speech is already in that language', function () {
    translatorAnswering(fn () => throw new LogicException('should not be called'));
    $upload = transcribedUpload(spoken: 'tl', translateTo: 'fil');

    TranslateTranscript::dispatch($upload);

    Storage::disk('local')->assertMissing($upload->translationPath());
    expect($upload->fresh()->status)->toBe(UploadStatus::Transcribing);
});

it('fails with a readable reason when the spoken language is unknown, and deletes the files', function () {
    translatorAnswering(fn () => throw new LogicException('should not be called'));
    $upload = transcribedUpload(spoken: null);
    $mediaPath = $upload->path;
    Storage::disk('local')->put($mediaPath, 'media');

    TranslateTranscript::dispatch($upload);

    $upload->refresh();
    expect($upload->status)->toBe(UploadStatus::Failed);
    expect($upload->error)->toStartWith("We couldn't tell which language is spoken");
    Storage::disk('local')->assertMissing([$mediaPath, $upload->transcriptPath()]);
});

it('fails with a general message when the translator keeps failing', function () {
    translatorAnswering(fn () => throw new ConnectionException('refused'));
    $upload = transcribedUpload();

    expect(fn () => TranslateTranscript::dispatch($upload))->toThrow(ConnectionException::class);

    expect($upload->fresh()->error)->toBe('Translation failed. Please try again.');
});

it('retries a few times with a pause, on the transcription queue', function () {
    $job = new TranslateTranscript(transcribedUpload());

    expect($job->tries)->toBe(3);
    expect($job->backoff)->toBe([30, 120]);
    expect($job->queue)->toBe('transcription');
});
