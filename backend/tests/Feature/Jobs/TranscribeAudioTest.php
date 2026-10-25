<?php

use App\Enums\UploadStatus;
use App\Jobs\TranscribeAudio;
use App\Models\Upload;
use App\Services\Transcription\Transcriber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

/**
 * A Transcriber that answers each call with the next result and records what it was asked.
 *
 * @param  list<array{language: string|null, segments: list<array{start: float, end: float, text: string}>}|Throwable>  $results
 */
function fakeTranscriber(array $results): object
{
    $transcriber = new class($results) implements Transcriber
    {
        /** @var list<array{path: string, language: string|null}> */
        public array $calls = [];

        public function __construct(private array $results) {}

        public function transcribe(string $audioPath, ?string $language): array
        {
            $this->calls[] = ['path' => $audioPath, 'language' => $language];
            $result = array_shift($this->results);

            return $result instanceof Throwable ? throw $result : $result;
        }
    };

    app()->instance(Transcriber::class, $transcriber);

    return $transcriber;
}

/**
 * Store a WAV piece lasting $seconds (8 kHz, 8-bit mono, as wavBytes() writes).
 */
function putAudioPiece(Upload $upload, int $number, float $seconds): void
{
    Storage::disk('local')->put(sprintf('%s/%04d.wav', $upload->audioDirectory(), $number), wavBytes((int) ($seconds * 8000)));
}

/**
 * @return array{language: string|null, segments: list<array{start: float, end: float, text: string}>}
 */
function transcriptOf(Upload $upload): array
{
    return json_decode(Storage::disk('local')->get($upload->transcriptPath()), true);
}

it('stops sending pieces to Whisper once the user has stopped (deleted) the upload', function () {
    $upload = Upload::factory()->uploaded()->create(['status' => UploadStatus::Transcribing]);
    putAudioPiece($upload, 0, 1.0);
    putAudioPiece($upload, 1, 1.0);
    putAudioPiece($upload, 2, 1.0);
    $transcriber = new class($upload) implements Transcriber
    {
        public int $calls = 0;

        public function __construct(private Upload $upload) {}

        public function transcribe(string $audioPath, ?string $language): array
        {
            $this->calls++;
            $this->upload->delete(); // the user presses Stop while the first piece is transcribed

            return ['language' => 'en', 'segments' => [['start' => 0.0, 'end' => 1.0, 'text' => 'Hi.']]];
        }
    };
    app()->instance(Transcriber::class, $transcriber);

    TranscribeAudio::dispatch($upload);

    expect($transcriber->calls)->toBe(1);
    Storage::disk('local')->assertMissing($upload->transcriptPath());
});

it('joins the pieces into one transcript, shifting each piece by the length of those before it', function () {
    $upload = Upload::factory()->uploaded()->create(['status' => UploadStatus::Transcribing, 'language' => 'en']);
    putAudioPiece($upload, 0, 2.0);
    putAudioPiece($upload, 1, 1.5);
    fakeTranscriber([
        ['language' => 'en', 'segments' => [['start' => 0.0, 'end' => 1.2, 'text' => ' Hello there.', 'words' => [
            ['start' => 0.0, 'end' => 0.5, 'word' => ' Hello'],
            ['start' => 0.5, 'end' => 1.2, 'word' => ' there.'],
        ]]]],
        ['language' => 'en', 'segments' => [['start' => 0.5, 'end' => 1.25, 'text' => 'General Kenobi.', 'words' => [
            ['start' => 0.5, 'end' => 0.75, 'word' => ' General'],
            ['start' => 0.75, 'end' => 1.25, 'word' => ' Kenobi.'],
        ]]]],
    ]);

    TranscribeAudio::dispatch($upload);

    expect(transcriptOf($upload))->toBe([
        'language' => 'en',
        'segments' => [
            ['start' => 0, 'end' => 1.2, 'text' => 'Hello there.', 'words' => [
                ['start' => 0, 'end' => 0.5, 'word' => ' Hello'],
                ['start' => 0.5, 'end' => 1.2, 'word' => ' there.'],
            ]],
            ['start' => 2.5, 'end' => 3.25, 'text' => 'General Kenobi.', 'words' => [
                ['start' => 2.5, 'end' => 2.75, 'word' => ' General'],
                ['start' => 2.75, 'end' => 3.25, 'word' => ' Kenobi.'],
            ]],
        ],
    ]);
});

it('sends the pieces in order and reports progress up to 95 percent', function () {
    $upload = Upload::factory()->uploaded()->create(['status' => UploadStatus::Transcribing]);
    putAudioPiece($upload, 1, 1.0);
    putAudioPiece($upload, 0, 1.0);
    $transcriber = fakeTranscriber(array_fill(0, 2, ['language' => 'en', 'segments' => [['start' => 0.0, 'end' => 1.0, 'text' => 'Hi']]]));

    TranscribeAudio::dispatch($upload);

    expect(array_map(fn (array $call) => basename($call['path']), $transcriber->calls))->toBe(['0000.wav', '0001.wav']);
    expect($upload->fresh()->progress())->toBe(95);
});

it('keeps later pieces in the language Whisper detected in the first one', function (?string $chosen) {
    $upload = Upload::factory()->uploaded()->create(['status' => UploadStatus::Transcribing, 'language' => $chosen]);
    putAudioPiece($upload, 0, 1.0);
    putAudioPiece($upload, 1, 1.0);
    $transcriber = fakeTranscriber(array_fill(0, 2, ['language' => 'ja', 'segments' => [['start' => 0.0, 'end' => 1.0, 'text' => 'こんにちは']]]));

    TranscribeAudio::dispatch($upload);

    expect(array_column($transcriber->calls, 'language'))->toBe([$chosen, 'ja']);
    expect(transcriptOf($upload)['language'])->toBe('ja');
})->with(['auto-detect' => 'auto', 'no language' => null]);

it('uses the language chosen by the user for every piece', function () {
    $upload = Upload::factory()->uploaded()->create(['status' => UploadStatus::Transcribing, 'language' => 'fil']);
    putAudioPiece($upload, 0, 1.0);
    putAudioPiece($upload, 1, 1.0);
    $transcriber = fakeTranscriber(array_fill(0, 2, ['language' => 'tl', 'segments' => [['start' => 0.0, 'end' => 1.0, 'text' => 'Kumusta']]]));

    TranscribeAudio::dispatch($upload);

    expect(array_column($transcriber->calls, 'language'))->toBe(['fil', 'fil']);
});

it('drops segments that contain only whitespace', function () {
    $upload = Upload::factory()->uploaded()->create(['status' => UploadStatus::Transcribing]);
    putAudioPiece($upload, 0, 1.0);
    fakeTranscriber([['language' => 'en', 'segments' => [
        ['start' => 0.0, 'end' => 0.4, 'text' => '  '],
        ['start' => 0.4, 'end' => 1.0, 'text' => 'Words'],
    ]]]);

    TranscribeAudio::dispatch($upload);

    expect(transcriptOf($upload)['segments'])->toBe([['start' => 0.4, 'end' => 1, 'text' => 'Words', 'words' => []]]);
});

it('fails with a clear message when no speech is found', function () {
    $upload = Upload::factory()->uploaded()->create(['status' => UploadStatus::Transcribing]);
    putAudioPiece($upload, 0, 1.0);
    fakeTranscriber([['language' => 'en', 'segments' => []]]);

    TranscribeAudio::dispatch($upload);

    $upload->refresh();
    expect($upload->status)->toBe(UploadStatus::Failed);
    expect($upload->error)->toBe('No speech was detected in this file.');
    Storage::disk('local')->assertMissing($upload->transcriptPath());
});

it('shows a generic message, not the technical error, when Whisper cannot be reached', function () {
    $upload = Upload::factory()->uploaded()->create(['status' => UploadStatus::Transcribing]);
    putAudioPiece($upload, 0, 1.0);
    fakeTranscriber([new ConnectionException('cURL error 7: Failed to connect to whisper port 9000')]);

    expect(fn () => TranscribeAudio::dispatch($upload))->toThrow(ConnectionException::class);

    $upload->refresh();
    expect($upload->status)->toBe(UploadStatus::Failed);
    expect($upload->error)->toBe('Transcription failed. Please try again.');
    Storage::disk('local')->assertMissing($upload->transcriptPath());
});
