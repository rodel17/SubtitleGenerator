<?php

use App\Exceptions\TranslationException;
use App\Services\Translation\TranscriptTranslator;
use App\Services\Translation\Translator;

/**
 * A Translator that answers from a dictionary (or upper-cases) and records each request.
 *
 * @param  array<string, string>  $dictionary
 */
function fakeTranslator(array $dictionary = []): object
{
    $translator = new class($dictionary) implements Translator
    {
        /** @var list<array{texts: list<string>, from: string, to: string}> */
        public array $requests = [];

        public function __construct(private array $dictionary) {}

        public function translate(array $texts, string $from, string $to): array
        {
            $this->requests[] = ['texts' => $texts, 'from' => $from, 'to' => $to];

            return array_map(fn (string $text): string => $this->dictionary[$text] ?? mb_strtoupper($text), $texts);
        }
    };

    app()->instance(Translator::class, $translator);

    return $translator;
}

function transcriptIn(?string $language, array $texts): array
{
    $segments = [];
    foreach ($texts as $index => $text) {
        $segments[] = ['start' => $index * 10.0, 'end' => $index * 10.0 + 4.0, 'text' => " {$text}", 'words' => []];
    }

    return ['language' => $language, 'segments' => $segments];
}

it('translates sentence by sentence, keeping each sentence\'s time', function () {
    $translator = fakeTranslator(['Good morning.' => 'Magandang umaga.', 'Thank you.' => 'Salamat.']);

    $result = app(TranscriptTranslator::class)->translate(transcriptIn('en', ['Good morning.', 'Thank you.']), 'fil');

    expect($translator->requests)->toBe([['texts' => ['Good morning.', 'Thank you.'], 'from' => 'en', 'to' => 'fil']]);
    expect($result['language'])->toBe('fil');
    expect(array_column($result['segments'], 'text'))->toBe(['Magandang umaga.', 'Salamat.']);
    expect($result['segments'][1])->toMatchArray(['start' => 10.0, 'end' => 14.0]);
});

it("spreads a sentence's time over the translated words by their length", function () {
    fakeTranslator(['Hi.' => 'ab abcd ab']);

    $words = app(TranscriptTranslator::class)->translate(transcriptIn('en', ['Hi.']), 'fil')['segments'][0]['words'];

    expect($words)->toBe([
        ['start' => 0.0, 'end' => 1.0, 'word' => 'ab'],
        ['start' => 1.0, 'end' => 3.0, 'word' => 'abcd'],
        ['start' => 3.0, 'end' => 4.0, 'word' => 'ab'],
    ]);
});

it('cuts Japanese into word-like pieces: after punctuation and where a new word starts', function () {
    fakeTranslator([
        'Good morning, everyone.' => 'みなさん、おはようございます。',
        'Ask what you can do.' => 'アメリカ人の皆さん自分の国のために何ができるかではなく自分たちのために',
    ]);

    $segments = app(TranscriptTranslator::class)->translate(transcriptIn('en', ['Good morning, everyone.', 'Ask what you can do.']), 'ja')['segments'];

    expect(array_column($segments[0]['words'], 'word'))->toBe(['みなさん、', 'おはようございます。']);
    expect(array_column($segments[1]['words'], 'word'))
        ->toBe(['アメリカ人の', '皆さん', '自分の', '国のために', '何ができるかではなく', '自分たちのために']);
    expect($segments[0]['words'][0]['start'])->toBe(0.0);
    expect(end($segments[0]['words'])['end'])->toBe(4.0);
});

it('cuts Chinese into short pieces', function () {
    fakeTranslator(['Thank you for watching.' => '谢谢你看这段视频。']);

    $words = app(TranscriptTranslator::class)->translate(transcriptIn('en', ['Thank you for watching.']), 'zh')['segments'][0]['words'];

    expect(array_column($words, 'word'))->toBe(['谢谢你看', '这段视频。']);
});

it('sends the sentences in batches and reports progress after each', function () {
    config(['translation.batch_size' => 2]);
    $translator = fakeTranslator();
    $progress = [];

    app(TranscriptTranslator::class)->translate(
        transcriptIn('en', ['one', 'two', 'three']),
        'fil',
        function (float $fraction) use (&$progress) {
            $progress[] = $fraction;
        },
    );

    expect(array_column($translator->requests, 'texts'))->toBe([['one', 'two'], ['three']]);
    expect($progress)->toBe([0.5, 1.0]);
});

it('sends each sentence of a segment, and long sentences clause by clause, then joins them', function () {
    $translator = fakeTranslator();
    $long = 'And so my fellow Americans, ask not what your country can do for you, ask what you can do for your country.';

    $result = app(TranscriptTranslator::class)->translate(transcriptIn('en', ["Hello there. {$long}"]), 'fil');

    expect($translator->requests[0]['texts'])->toBe([
        'Hello there.',
        'And so my fellow Americans, ask not what your country can do for you,',
        'ask what you can do for your country.',
    ]);
    expect($result['segments'])->toHaveCount(1);
    expect($result['segments'][0]['text'])->toBe(mb_strtoupper("Hello there. {$long}"));
});

it('joins the pieces without spaces in Japanese and Chinese', function () {
    fakeTranslator(['One.' => 'いち。', 'Two.' => 'に。']);

    $result = app(TranscriptTranslator::class)->translate(transcriptIn('en', ['One. Two.']), 'ja');

    expect($result['segments'][0]['text'])->toBe('いち。に。');
});

it('drops sentences that come back empty', function () {
    fakeTranslator(['Hmm.' => '  ']);

    $result = app(TranscriptTranslator::class)->translate(transcriptIn('en', ['Hmm.', 'Yes.']), 'fil');

    expect(array_column($result['segments'], 'text'))->toBe(['YES.']);
});

it('refuses when the spoken language is unknown', function (?string $language) {
    fakeTranslator();

    expect(fn () => app(TranscriptTranslator::class)->translate(transcriptIn($language, ['Hi.']), 'fil'))
        ->toThrow(TranslationException::class, "We couldn't tell which language is spoken");
})->with(['missing' => null, 'auto' => 'auto']);

it('knows when the transcript is already in the target language', function () {
    $translator = app(TranscriptTranslator::class);

    expect($translator->needsTranslation(['language' => 'tl'], 'fil'))->toBeFalse();
    expect($translator->needsTranslation(['language' => 'fil'], 'fil'))->toBeFalse();
    expect($translator->needsTranslation(['language' => 'en'], 'fil'))->toBeTrue();
    expect($translator->needsTranslation(['language' => null], 'fil'))->toBeTrue();
});
