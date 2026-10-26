<?php

use App\Services\Transcription\CueBuilder;

/**
 * A segment whose words each last $seconds, back to back from $start.
 *
 * @return array{start: float, end: float, text: string, words: list<array{start: float, end: float, word: string}>}
 */
function segmentOf(string $text, float $start = 0.0, float $seconds = 0.3): array
{
    $words = [];

    foreach (explode(' ', $text) as $index => $word) {
        $words[] = ['start' => $start + $index * $seconds, 'end' => $start + ($index + 1) * $seconds, 'word' => " {$word}"];
    }

    return ['start' => $start, 'end' => end($words)['end'], 'text' => " {$text}", 'words' => $words];
}

test('a short sentence stays one cue on one line, with its word times', function () {
    $cues = (new CueBuilder)->build([segmentOf('Hello there.', start: 2.0)]);

    expect($cues)->toBe([['start' => 2.0, 'end' => 2.6, 'text' => 'Hello there.']]);
});

test('a long sentence is cut at a comma once a cue holds a full line, and wrapped onto two even lines', function () {
    $cues = (new CueBuilder)->build([
        segmentOf('And so my fellow Americans, ask not what your country can do for you, ask what you can do for your country.'),
    ]);

    expect(array_column($cues, 'text'))->toBe([
        "And so my fellow Americans, ask not\nwhat your country can do for you,",
        'ask what you can do for your country.',
    ]);
    expect($cues[1]['start'])->toBe($cues[0]['end']);
});

test('no cue is longer than two lines of 42 characters', function () {
    $cues = (new CueBuilder)->build([segmentOf(implode(' ', array_fill(0, 60, 'word')), seconds: 0.05)]);

    foreach ($cues as $cue) {
        expect(mb_strlen(str_replace("\n", ' ', $cue['text'])))->toBeLessThanOrEqual(84);
        expect(explode("\n", $cue['text']))->toHaveCount(2);
    }
    expect(implode(' ', array_map(fn ($cue) => str_replace("\n", ' ', $cue['text']), $cues)))
        ->toBe(implode(' ', array_fill(0, 60, 'word')));
});

test('when a limit forces a cut, the cue ends at its last comma and the rest moves on', function () {
    // Spoken slowly, so the 7 second limit is reached before the comma rule applies
    $cues = (new CueBuilder)->build([
        segmentOf('And so my fellow Americans, ask not what your country can do for you, ask what you can do for your country.', seconds: 0.6),
    ]);

    expect(array_column($cues, 'text'))->toBe([
        'And so my fellow Americans,',
        'ask not what your country can do for you,',
        'ask what you can do for your country.',
    ]);
    expect($cues[1]['start'])->toBe($cues[0]['end']);
});

test('no cue lasts longer than seven seconds, even when spoken slowly', function () {
    $cues = (new CueBuilder)->build([segmentOf('one two three four five six seven eight nine ten', seconds: 1.5)]);

    foreach ($cues as $cue) {
        expect($cue['end'] - $cue['start'])->toBeLessThanOrEqual(CueBuilder::MAX_DURATION);
    }
    expect(array_column($cues, 'text'))->toBe(['one two three four', 'five six seven eight', 'nine ten']);
});

test('every sentence starts a new cue', function () {
    $cues = (new CueBuilder)->build([segmentOf('Stop. Who goes there? Me!')]);

    expect(array_column($cues, 'text'))->toBe(['Stop.', 'Who goes there?', 'Me!']);
});

test('a cue never spans two segments', function () {
    $cues = (new CueBuilder)->build([segmentOf('First part'), segmentOf('second part', start: 5.0)]);

    expect(array_column($cues, 'text'))->toBe(['First part', 'second part']);
});

test('a segment without word times is kept whole and wrapped', function () {
    $cues = (new CueBuilder)->build([[
        'start' => 1.0,
        'end' => 4.0,
        'text' => ' This segment came back without any word timestamps at all.',
    ]]);

    expect($cues)->toBe([[
        'start' => 1.0,
        'end' => 4.0,
        'text' => "This segment came back without\nany word timestamps at all.",
    ]]);
});

test('Japanese words are joined without spaces, on lines of at most 16 characters', function () {
    $words = [];
    foreach (['今日は', 'とても', '良い', '天気', 'ですね、', '散歩に', '行きましょう。'] as $index => $word) {
        $words[] = ['start' => $index * 0.4, 'end' => ($index + 1) * 0.4, 'word' => $word];
    }

    $cues = (new CueBuilder)->build([['start' => 0.0, 'end' => 2.8, 'text' => '', 'words' => $words]], 'ja');

    expect(implode('', array_map(fn ($cue) => str_replace("\n", '', $cue['text']), $cues)))
        ->toBe('今日はとても良い天気ですね、散歩に行きましょう。');
    foreach ($cues as $cue) {
        expect($cue['text'])->not->toContain(' ');
        foreach (explode("\n", $cue['text']) as $line) {
            expect(mb_strlen($line))->toBeLessThanOrEqual(CueBuilder::MAX_LINE_LENGTH_WITHOUT_SPACES);
        }
    }
});

test('a Japanese cue is wrapped between words, never inside one', function () {
    $words = [];
    foreach (['アメリカ人の', '皆さん', '自分の', '国のために'] as $index => $word) {
        $words[] = ['start' => $index * 0.5, 'end' => ($index + 1) * 0.5, 'word' => $word];
    }

    $cues = (new CueBuilder)->build([['start' => 0.0, 'end' => 2.0, 'text' => '', 'words' => $words]], 'ja');

    expect(array_column($cues, 'text'))->toBe(["アメリカ人の皆さん\n自分の国のために"]);
});

test('a long line without spaces is wrapped after punctuation near the middle', function () {
    $cues = (new CueBuilder)->build([[
        'start' => 0.0,
        'end' => 3.0,
        'text' => '我们今天去公园散步，然后一起吃晚饭吧。',
    ]], 'zh');

    expect($cues[0]['text'])->toBe("我们今天去公园散步，\n然后一起吃晚饭吧。");
});

test('languages with spaces keep the 42 character lines', function () {
    $cues = (new CueBuilder)->build([segmentOf('Magandang umaga sa inyong lahat.')], 'fil');

    expect($cues[0]['text'])->toBe('Magandang umaga sa inyong lahat.');
});

test('blank words are skipped', function () {
    $segment = segmentOf('Hello there.');
    array_splice($segment['words'], 1, 0, [['start' => 0.3, 'end' => 0.3, 'word' => ' ']]);

    expect((new CueBuilder)->build([$segment])[0]['text'])->toBe('Hello there.');
});
