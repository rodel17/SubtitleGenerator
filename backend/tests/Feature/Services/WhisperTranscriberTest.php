<?php

use App\Services\Transcription\WhisperTranscriber;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['transcription.whisper.url' => 'http://whisper.test']);
    Storage::fake('local');
    Storage::disk('local')->put('audio/piece.wav', wavBytes());
    $this->audioPath = Storage::disk('local')->path('audio/piece.wav');
});

function whisperJson(): array
{
    return [
        'language' => 'en',
        'text' => ' Hello there.',
        'segments' => [
            ['id' => 1, 'seek' => 0, 'start' => 0.0, 'end' => 1.5, 'text' => ' Hello there.', 'tokens' => [50364], 'avg_logprob' => -0.2, 'words' => [
                ['start' => 0.0, 'end' => 0.6, 'word' => ' Hello', 'probability' => 0.98],
                ['start' => 0.6, 'end' => 1.5, 'word' => ' there.', 'probability' => 0.95],
            ]],
        ],
    ];
}

it('sends the audio file and returns the segments and word times in seconds', function () {
    Http::fake(['whisper.test/asr*' => Http::response(whisperJson())]);

    $result = (new WhisperTranscriber)->transcribe($this->audioPath, 'en');

    expect($result)->toBe([
        'language' => 'en',
        'segments' => [[
            'start' => 0.0,
            'end' => 1.5,
            'text' => ' Hello there.',
            'words' => [
                ['start' => 0.0, 'end' => 0.6, 'word' => ' Hello'],
                ['start' => 0.6, 'end' => 1.5, 'word' => ' there.'],
            ],
        ]],
    ]);
    Http::assertSent(fn (Request $request) => $request->isMultipart()
        && $request->hasFile('audio_file', filename: 'piece.wav'));
});

it('asks for JSON output with silence filtering and word times', function () {
    Http::fake(['whisper.test/asr*' => Http::response(whisperJson())]);

    (new WhisperTranscriber)->transcribe($this->audioPath, 'en');

    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query['output'] === 'json'
            && $query['task'] === 'transcribe'
            && $query['vad_filter'] === 'true'
            && $query['word_timestamps'] === 'true';
    });
});

it('maps the app language to the code Whisper expects', function (?string $language, ?string $sent) {
    Http::fake(['whisper.test/asr*' => Http::response(whisperJson())]);

    (new WhisperTranscriber)->transcribe($this->audioPath, $language);

    Http::assertSent(function (Request $request) use ($sent) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['language'] ?? null) === $sent;
    });
})->with([
    'english' => ['en', 'en'],
    'filipino is tagalog in whisper' => ['fil', 'tl'],
    'auto-detect sends no language' => ['auto', null],
    'no language sends none' => [null, null],
]);

it('returns no segments when Whisper hears nothing', function () {
    Http::fake(['whisper.test/asr*' => Http::response(['language' => 'en', 'text' => '', 'segments' => []])]);

    expect((new WhisperTranscriber)->transcribe($this->audioPath, null)['segments'])->toBe([]);
});

it('throws when the service returns an error, so the job can retry', function () {
    Http::fake(['whisper.test/asr*' => Http::response('Internal Server Error', 500)]);

    (new WhisperTranscriber)->transcribe($this->audioPath, 'en');
})->throws(RequestException::class);
