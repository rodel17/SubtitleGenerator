<?php

namespace App\Services\Transcription;

interface Transcriber
{
    /**
     * Transcribe one audio file. Segment times are in seconds from the start of that file.
     *
     * @param  string|null  $language  ISO code as chosen in the app ("en", "fil", …); null or "auto" to detect
     * @return array{language: string|null, segments: list<array{start: float, end: float, text: string, words: list<array{start: float, end: float, word: string}>}>}
     */
    public function transcribe(string $audioPath, ?string $language): array;
}
