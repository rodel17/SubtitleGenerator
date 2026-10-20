<?php

use App\Models\Upload;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Bytes of a tiny but real WAV file, so content-based MIME detection sees audio/x-wav.
 */
function wavBytes(int $sampleCount = 30): string
{
    return 'RIFF'.pack('V', 36 + $sampleCount).'WAVE'
        .'fmt '.pack('V', 16).pack('v', 1).pack('v', 1).pack('V', 8000).pack('V', 8000).pack('v', 1).pack('v', 8)
        .'data'.pack('V', $sampleCount).str_repeat("\x80", $sampleCount);
}

/**
 * Bytes of a minimal MPEG transport stream (188-byte packets), detected as video/MP2T.
 */
function mpegTsBytes(int $packetCount = 10): string
{
    return str_repeat("\x47\x40\x00\x10".str_repeat("\xff", 184), $packetCount);
}

/**
 * Send one chunk through the API.
 */
function postChunk(Upload $upload, int|string $index, string $bytes): TestResponse
{
    return test()->post("/api/uploads/{$upload->id}/chunks", [
        'index' => $index,
        'chunk' => UploadedFile::fake()->createWithContent('chunk', $bytes),
    ], ['Accept' => 'application/json']);
}

/**
 * Send every chunk of $bytes through the API, optionally in a custom order of indexes.
 *
 * @param  list<int>|null  $order
 */
function sendAllChunks(Upload $upload, string $bytes, ?array $order = null): void
{
    $chunks = str_split($bytes, $upload->chunk_size);

    foreach ($order ?? array_keys($chunks) as $index) {
        postChunk($upload, $index, $chunks[$index])->assertNoContent();
    }
}
