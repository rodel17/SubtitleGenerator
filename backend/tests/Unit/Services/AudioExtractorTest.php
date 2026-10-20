<?php

use App\Services\Transcription\AudioExtractor;

test('reads the latest position from a block of ffmpeg progress output', function () {
    $output = "frame=0\nout_time_us=1500000\nprogress=continue\nout_time_us=4250000\nprogress=continue\n";

    expect(AudioExtractor::progressSeconds($output))->toBe(4.25);
});

test('returns null when the output has no position', function (string $output) {
    expect(AudioExtractor::progressSeconds($output))->toBeNull();
})->with([
    'empty' => '',
    'other keys only' => "bitrate=N/A\nprogress=continue\n",
    'not yet known' => "out_time_us=N/A\n",
]);
