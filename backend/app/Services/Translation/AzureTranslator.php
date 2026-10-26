<?php

namespace App\Services\Translation;

use App\Exceptions\TranslatorUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Microsoft Azure Translator ("Enhanced"): better quality than the local model,
 * but the text is sent to Microsoft (which doesn't store it). Any failure is a
 * TranslatorUnavailableException, so the caller can fall back to the local engine.
 */
class AzureTranslator implements Translator
{
    /**
     * Requests stay well under Azure's limits (1,000 texts, 50,000 characters)
     * and the free tier's ~33,000 characters a minute.
     */
    private const TEXTS_PER_REQUEST = 100;

    private const CHARACTERS_PER_REQUEST = 10_000;

    /**
     * A 429 means the per-minute allowance is used up; waiting usually frees it.
     */
    private const RATE_LIMIT_RETRIES = 3;

    private const RATE_LIMIT_WAIT_SECONDS = 20;

    /**
     * Where Azure's code differs from Whisper's / the app's (after Languages::normalize).
     */
    private const CODES = ['tl' => 'fil', 'zh' => 'zh-Hans', 'yue' => 'yue', 'no' => 'nb', 'sr' => 'sr-Cyrl', 'mn' => 'mn-Cyrl'];

    public static function isConfigured(): bool
    {
        return filled(config('translation.azure.key')) && filled(config('translation.azure.region'));
    }

    /**
     * @param  list<string>  $texts
     * @return list<string>
     *
     * @throws TranslatorUnavailableException
     */
    public function translate(array $texts, string $from, string $to): array
    {
        if (! self::isConfigured()) {
            throw new TranslatorUnavailableException('Azure Translator is not configured.');
        }

        $translations = array_fill(0, count($texts), '');

        foreach ($this->requests($texts) as $indexes) {
            $results = $this->send(array_map(fn (int $index): array => ['Text' => $texts[$index]], $indexes), $from, $to);

            foreach ($indexes as $position => $index) {
                $translations[$index] = (string) ($results[$position]['translations'][0]['text'] ?? '');
            }
        }

        return $translations;
    }

    /**
     * Indexes of the non-blank texts, grouped into requests within the size limits.
     *
     * @param  list<string>  $texts
     * @return list<list<int>>
     */
    private function requests(array $texts): array
    {
        $requests = [];
        $current = [];
        $characters = 0;

        foreach ($texts as $index => $text) {
            if (trim($text) === '') {
                continue;
            }

            $length = mb_strlen($text);

            if ($current !== [] && (count($current) >= self::TEXTS_PER_REQUEST || $characters + $length > self::CHARACTERS_PER_REQUEST)) {
                $requests[] = $current;
                $current = [];
                $characters = 0;
            }

            $current[] = $index;
            $characters += $length;
        }

        if ($current !== []) {
            $requests[] = $current;
        }

        return $requests;
    }

    /**
     * @param  list<array{Text: string}>  $body
     * @return list<array{translations: list<array{text: string}>}>
     */
    private function send(array $body, string $from, string $to): array
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                $response = Http::baseUrl(config('translation.azure.endpoint'))
                    ->timeout(config('translation.azure.timeout'))
                    ->withHeaders([
                        'Ocp-Apim-Subscription-Key' => config('translation.azure.key'),
                        'Ocp-Apim-Subscription-Region' => config('translation.azure.region'),
                    ])
                    ->withQueryParameters(['api-version' => '3.0', 'from' => self::code($from), 'to' => self::code($to)])
                    ->post('/translate', $body);
            } catch (ConnectionException $exception) {
                throw new TranslatorUnavailableException("Azure Translator can't be reached: {$exception->getMessage()}", previous: $exception);
            }

            if ($response->status() === 429 && $attempt < self::RATE_LIMIT_RETRIES) {
                Sleep::for(self::RATE_LIMIT_WAIT_SECONDS)->seconds();

                continue;
            }

            if ($response->failed() || ! is_array($response->json()) || count($response->json()) !== count($body)) {
                throw new TranslatorUnavailableException('Azure Translator failed: '.$this->describe($response));
            }

            return $response->json();
        }
    }

    /**
     * E.g. "403 (403001: The operation isn't allowed because the subscription has exceeded its free quota.)"
     */
    private function describe(Response $response): string
    {
        $error = $response->json('error');

        return $response->status().(is_array($error) ? " ({$error['code']}: {$error['message']})" : '');
    }

    private static function code(string $language): string
    {
        $normalized = Languages::normalize($language) ?? $language;

        return self::CODES[$normalized] ?? $normalized;
    }
}
