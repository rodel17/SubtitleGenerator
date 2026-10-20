<?php

namespace App\Services\Transcription;

use InvalidArgumentException;

class SubtitleWriter
{
    /**
     * Render transcript segments as an SRT or WebVTT file.
     *
     * @param  list<array{start: float, end: float, text: string}>  $segments
     */
    public function write(array $segments, string $format): string
    {
        return match ($format) {
            'srt' => $this->srt($segments),
            'vtt' => $this->vtt($segments),
            default => throw new InvalidArgumentException("Unsupported subtitle format [{$format}]."),
        };
    }

    /**
     * @param  list<array{start: float, end: float, text: string}>  $segments
     */
    private function srt(array $segments): string
    {
        $cues = [];

        foreach (array_values($segments) as $number => $segment) {
            $cues[] = ($number + 1)."\n"
                .$this->timestamp($segment['start'], ',').' --> '.$this->timestamp($segment['end'], ',')."\n"
                .$segment['text'];
        }

        return implode("\n\n", $cues)."\n";
    }

    /**
     * @param  list<array{start: float, end: float, text: string}>  $segments
     */
    private function vtt(array $segments): string
    {
        $cues = array_map(
            fn (array $segment): string => $this->timestamp($segment['start'], '.').' --> '.$this->timestamp($segment['end'], '.')."\n"
                .$segment['text'],
            $segments,
        );

        return "WEBVTT\n\n".implode("\n\n", $cues)."\n";
    }

    /**
     * "HH:MM:SS,mmm" (SRT) or "HH:MM:SS.mmm" (VTT).
     */
    private function timestamp(float $seconds, string $millisecondSeparator): string
    {
        $milliseconds = (int) round(max(0.0, $seconds) * 1000);

        return sprintf(
            '%02d:%02d:%02d%s%03d',
            intdiv($milliseconds, 3_600_000),
            intdiv($milliseconds, 60_000) % 60,
            intdiv($milliseconds, 1000) % 60,
            $millisecondSeparator,
            $milliseconds % 1000,
        );
    }
}
