<?php

use App\Exceptions\TranslatorUnavailableException;
use App\Services\Translation\AzureTranslator;
use App\Services\Translation\LocalTranslator;
use App\Services\Translation\TranslationEngines;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    config([
        'translation.azure.key' => 'test-key',
        'translation.azure.region' => 'southeastasia',
        'translation.azure.endpoint' => 'https://azure.test',
    ]);
    Sleep::fake();
});

it('sends the texts to the Asia Pacific endpoint with the key, region and Azure language codes', function () {
    Http::fake(['azure.test/translate*' => Http::response([
        ['translations' => [['text' => 'Magandang umaga.', 'to' => 'fil']]],
        ['translations' => [['text' => 'Salamat.', 'to' => 'fil']]],
    ])]);

    $result = (new AzureTranslator)->translate(['Good morning.', 'Thank you.'], 'en', 'fil');

    expect($result)->toBe(['Magandang umaga.', 'Salamat.']);
    Http::assertSent(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), 'https://azure.test/translate?')
            && $query === ['api-version' => '3.0', 'from' => 'en', 'to' => 'fil']
            && $request->header('Ocp-Apim-Subscription-Key') === ['test-key']
            && $request->header('Ocp-Apim-Subscription-Region') === ['southeastasia']
            && $request->data() === [['Text' => 'Good morning.'], ['Text' => 'Thank you.']];
    });
});

it('uses Azure codes where they differ: Filipino and Simplified Chinese', function (string $from, string $to, string $azureFrom, string $azureTo) {
    Http::fake(['azure.test/translate*' => Http::response([['translations' => [['text' => 'x']]]])]);

    (new AzureTranslator)->translate(['Hi.'], $from, $to);

    Http::assertSent(function (Request $request) use ($azureFrom, $azureTo) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query['from'] === $azureFrom && $query['to'] === $azureTo;
    });
})->with([
    'Tagalog speech to Chinese' => ['tl', 'zh', 'fil', 'zh-Hans'],
    'Filipino to Korean' => ['fil', 'ko', 'fil', 'ko'],
    'Japanese to English' => ['ja', 'en', 'ja', 'en'],
]);

it('keeps blank texts blank without sending them', function () {
    Http::fake(['azure.test/translate*' => Http::response([['translations' => [['text' => 'Oo.']]]])]);

    expect((new AzureTranslator)->translate(['', 'Yes.', '  '], 'en', 'fil'))->toBe(['', 'Oo.', '']);
    Http::assertSent(fn (Request $request) => $request->data() === [['Text' => 'Yes.']]);
});

it('splits large batches into requests of at most 100 texts and 10,000 characters', function () {
    Http::fake(['azure.test/translate*' => fn (Request $request) => Http::response(array_map(
        fn (array $item) => ['translations' => [['text' => strtoupper($item['Text'])]]],
        $request->data(),
    ))]);
    $short = array_map(fn (int $number) => "line {$number}", range(1, 150));
    $long = array_fill(0, 3, str_repeat('a', 4000));

    expect((new AzureTranslator)->translate($short, 'en', 'fil'))->toBe(array_map(strtoupper(...), $short));
    expect((new AzureTranslator)->translate($long, 'en', 'fil'))->toBe(array_map(strtoupper(...), $long));

    Http::assertSentCount(2 + 2);
});

it('waits and retries when the per-minute allowance is used up', function () {
    Http::fakeSequence('azure.test/translate*')
        ->push(['error' => ['code' => 429001, 'message' => 'Too many requests.']], 429)
        ->push([['translations' => [['text' => 'Salamat.']]]]);

    expect((new AzureTranslator)->translate(['Thank you.'], 'en', 'fil'))->toBe(['Salamat.']);
    Sleep::assertSleptTimes(1);
});

it('gives up, so the local engine can take over, when Azure keeps refusing', function () {
    Http::fake(['azure.test/translate*' => Http::response(['error' => ['code' => 429001, 'message' => 'Too many requests.']], 429)]);

    expect(fn () => (new AzureTranslator)->translate(['Hi.'], 'en', 'fil'))
        ->toThrow(TranslatorUnavailableException::class, '429 (429001: Too many requests.)');
    Sleep::assertSleptTimes(3);
});

it('is unavailable when the free monthly quota is used up', function () {
    Http::fake(['azure.test/translate*' => Http::response(['error' => [
        'code' => 403001,
        'message' => "The operation isn't allowed because the subscription has exceeded its free quota.",
    ]], 403)]);

    expect(fn () => (new AzureTranslator)->translate(['Hi.'], 'en', 'fil'))
        ->toThrow(TranslatorUnavailableException::class, '403 (403001:');
    Sleep::assertNeverSlept();
});

it('is unavailable when Azure cannot be reached', function () {
    Http::fake(['azure.test/translate*' => fn () => throw new ConnectionException('timed out')]);

    expect(fn () => (new AzureTranslator)->translate(['Hi.'], 'en', 'fil'))
        ->toThrow(TranslatorUnavailableException::class, "Azure Translator can't be reached");
});

it('is unavailable, without calling Azure, when no key or region is set', function (string $setting) {
    config([$setting => '']);
    Http::fake();

    expect(AzureTranslator::isConfigured())->toBeFalse();
    expect(fn () => (new AzureTranslator)->translate(['Hi.'], 'en', 'fil'))->toThrow(TranslatorUnavailableException::class);
    Http::assertNothingSent();
})->with(['translation.azure.key', 'translation.azure.region']);

it('offers Enhanced only when Azure is configured, and hands out the right engine', function () {
    $engines = new TranslationEngines;

    expect($engines->available())->toBe(['local', 'azure']);
    expect($engines->translator('azure'))->toBeInstanceOf(AzureTranslator::class);
    expect($engines->translator('local'))->toBeInstanceOf(LocalTranslator::class);

    config(['translation.azure.key' => null]);
    expect($engines->available())->toBe(['local']);
    expect($engines->isAvailable('azure'))->toBeFalse();
});
