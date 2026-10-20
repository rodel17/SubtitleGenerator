<?php

namespace App\Services\Transcription;

use App\Exceptions\TranscriptionException;
use App\Models\Upload;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class AudioExtractor
{
    /**
     * Convert the upload's media to 16 kHz mono WAV (what Whisper expects) and
     * split it into numbered pieces of `transcription.piece_seconds`.
     *
     * @param  (callable(float $fraction): void)|null  $onProgress  Called with 0.0–1.0 as ffmpeg works
     *
     * @throws TranscriptionException When the file has no readable audio
     */
    public function extract(Upload $upload, ?callable $onProgress = null): void
    {
        $disk = $this->disk();
        $input = $disk->path($upload->path);
        $directory = $upload->audioDirectory();

        $disk->deleteDirectory($directory);
        $disk->makeDirectory($directory);

        $duration = $this->mediaDuration($input);

        $result = Process::timeout(1800)->run([
            'ffmpeg', '-hide_banner', '-nostdin', '-y',
            '-i', $input,
            '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'pcm_s16le',
            '-f', 'segment', '-segment_time', (string) config('transcription.piece_seconds'), '-reset_timestamps', '1',
            '-progress', 'pipe:1', '-nostats',
            $disk->path($directory).'/%04d.wav',
        ], function (string $type, string $output) use ($duration, $onProgress): void {
            $seconds = $type === 'out' ? self::progressSeconds($output) : null;

            if ($onProgress !== null && $seconds !== null && $duration > 0) {
                $onProgress(min(1.0, $seconds / $duration));
            }
        });

        if ($result->failed() || $this->pieces($upload) === []) {
            $disk->deleteDirectory($directory);

            throw new TranscriptionException('No audio track could be read from this file.');
        }
    }

    /**
     * Extracted audio pieces in playback order (paths on the uploads disk).
     *
     * @return list<string>
     */
    public function pieces(Upload $upload): array
    {
        $pieces = array_values(array_filter(
            $this->disk()->files($upload->audioDirectory()),
            fn (string $file): bool => str_ends_with($file, '.wav'),
        ));

        sort($pieces);

        return $pieces;
    }

    /**
     * Length of a PCM WAV file in seconds, read from its header.
     */
    public function wavDuration(string $absolutePath): float
    {
        $handle = fopen($absolutePath, 'rb');
        $header = fread($handle, 4096) ?: '';
        fclose($handle);

        $fmt = strpos($header, 'fmt ');
        $data = strpos($header, 'data');

        if ($fmt === false || $data === false) {
            return 0.0;
        }

        $byteRate = unpack('V', substr($header, $fmt + 16, 4))[1];
        $dataSize = unpack('V', substr($header, $data + 4, 4))[1];

        return $byteRate > 0 ? $dataSize / $byteRate : 0.0;
    }

    /**
     * Seconds of media processed so far, from a block of `ffmpeg -progress` output.
     */
    public static function progressSeconds(string $output): ?float
    {
        if (preg_match_all('/^out_time_us=(\d+)$/m', $output, $matches) === 0) {
            return null;
        }

        return (int) end($matches[1]) / 1_000_000;
    }

    private function mediaDuration(string $absolutePath): float
    {
        $result = Process::timeout(60)->run([
            'ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $absolutePath,
        ]);

        return (float) trim($result->output());
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('uploads.disk'));
    }
}
