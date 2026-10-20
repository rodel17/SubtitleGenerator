<?php

namespace App\Services\Transcription;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Client for the "whisper" container (openai-whisper-asr-webservice, faster-whisper engine).
 */
class WhisperTranscriber implements Transcriber
{
    /**
     * App language codes that Whisper names differently.
     *
     * @var array<string, string>
     */
    private const LANGUAGE_ALIASES = ['fil' => 'tl'];

    /**
     * @throws RequestException When the service is unreachable or rejects the audio
     */
    public function transcribe(string $audioPath, ?string $language): array
    {
        $query = array_filter([
            'task' => 'transcribe',
            'output' => 'json',
            'encode' => 'true',
            // Skips silence, where Whisper tends to invent text
            'vad_filter' => 'true',
            // Lets long segments be split into readable cues at exact word times
            'word_timestamps' => 'true',
            'language' => $this->whisperLanguage($language),
        ]);

        $audio = fopen($audioPath, 'rb');

        try {
            $response = Http::baseUrl(config('transcription.whisper.url'))
                ->timeout(config('transcription.whisper.timeout'))
                ->attach('audio_file', $audio, basename($audioPath))
                ->post('/asr?'.http_build_query($query))
                ->throw();
        } finally {
            if (is_resource($audio)) {
                fclose($audio);
            }
        }

        return [
            'language' => $response->json('language'),
            'segments' => array_map(fn (array $segment): array => [
                'start' => (float) $segment['start'],
                'end' => (float) $segment['end'],
                'text' => (string) $segment['text'],
                'words' => array_map(fn (array $word): array => [
                    'start' => (float) $word['start'],
                    'end' => (float) $word['end'],
                    'word' => (string) $word['word'],
                ], $segment['words'] ?? []),
            ], $response->json('segments') ?? []),
        ];
    }

    private function whisperLanguage(?string $language): ?string
    {
        if ($language === null || $language === 'auto') {
            return null;
        }

        return self::LANGUAGE_ALIASES[$language] ?? $language;
    }
}
