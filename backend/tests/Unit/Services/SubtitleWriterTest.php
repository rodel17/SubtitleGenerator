<?php

use App\Services\Transcription\SubtitleWriter;

test('srt numbers each cue and uses a comma before the milliseconds', function () {
    $srt = (new SubtitleWriter)->write([
        ['start' => 0.0, 'end' => 2.5, 'text' => 'First line.'],
        ['start' => 2.5, 'end' => 4.0, 'text' => 'Second line.'],
    ], 'srt');

    expect($srt)->toBe("1\n00:00:00,000 --> 00:00:02,500\nFirst line.\n\n2\n00:00:02,500 --> 00:00:04,000\nSecond line.\n");
});

test('vtt starts with the WEBVTT header and uses a dot before the milliseconds', function () {
    $vtt = (new SubtitleWriter)->write([['start' => 61.25, 'end' => 62.0, 'text' => 'Hi.']], 'vtt');

    expect($vtt)->toBe("WEBVTT\n\n00:01:01.250 --> 00:01:02.000\nHi.\n");
});

test('timestamps carry into hours and round to the nearest millisecond', function (float $seconds, string $expected) {
    $srt = (new SubtitleWriter)->write([['start' => $seconds, 'end' => $seconds, 'text' => 'x']], 'srt');

    expect(explode(' --> ', explode("\n", $srt)[1])[0])->toBe($expected);
})->with([
    'over an hour' => [3723.004, '01:02:03,004'],
    'rounds half a millisecond up' => [1.0005, '00:00:01,001'],
    'rounds just below a second up to it' => [59.9999, '00:01:00,000'],
    'negative clamps to zero' => [-0.2, '00:00:00,000'],
]);

test('rejects an unknown format', function () {
    (new SubtitleWriter)->write([], 'ass');
})->throws(InvalidArgumentException::class, 'Unsupported subtitle format [ass].');
