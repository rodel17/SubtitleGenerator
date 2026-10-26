<?php

use App\Exceptions\TranslationException;
use App\Services\Translation\LocalTranslator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['translation.local.url' => 'http://translator.test']);
});

it('asks the translator service with NLLB language codes', function () {
    Http::fake(['translator.test/translate' => Http::response(['translations' => ['Magandang umaga.', 'Salamat.']])]);

    $result = (new LocalTranslator)->translate(['Good morning.', 'Thank you.'], 'en', 'fil');

    expect($result)->toBe(['Magandang umaga.', 'Salamat.']);
    Http::assertSent(fn (Request $request) => $request->url() === 'http://translator.test/translate'
        && $request['source'] === 'eng_Latn'
        && $request['target'] === 'tgl_Latn'
        && $request['texts'] === ['Good morning.', 'Thank you.']);
});

it('splits many texts over several requests, keeping the order', function () {
    Http::fake(['translator.test/translate' => fn (Request $request) => Http::response([
        'translations' => array_map(fn (string $text) => "fil:{$text}", $request['texts']),
    ])]);
    $texts = array_map(fn (int $number) => "line {$number}", range(1, 130));

    $result = (new LocalTranslator)->translate($texts, 'en', 'fil');

    expect($result)->toBe(array_map(fn (string $text) => "fil:{$text}", $texts));
    Http::assertSentCount(3);
});

it('makes no request for nothing to translate', function () {
    Http::fake();

    expect((new LocalTranslator)->translate([], 'en', 'ja'))->toBe([]);
    Http::assertNothingSent();
});

it('refuses languages NLLB cannot translate, without calling the service', function () {
    Http::fake();

    expect(fn () => (new LocalTranslator)->translate(['Hi.'], 'xx', 'fil'))
        ->toThrow(TranslationException::class, "Translating from this language isn't supported yet.");
    Http::assertNothingSent();
});

it('fails, so the job can retry, when the service errors or is down', function () {
    Http::fake(['translator.test/translate' => Http::response(['error' => 'boom'], 500)]);
    expect(fn () => (new LocalTranslator)->translate(['Hi.'], 'en', 'fil'))->toThrow(RequestException::class);

    Http::fake(['translator.test/translate' => fn () => throw new ConnectionException('refused')]);
    expect(fn () => (new LocalTranslator)->translate(['Hi.'], 'en', 'fil'))->toThrow(ConnectionException::class);
});

it('fails when the service answers with the wrong number of translations', function () {
    Http::fake(['translator.test/translate' => Http::response(['translations' => ['Isa lang.']])]);

    expect(fn () => (new LocalTranslator)->translate(['One.', 'Two.'], 'en', 'fil'))->toThrow(RuntimeException::class);
});
