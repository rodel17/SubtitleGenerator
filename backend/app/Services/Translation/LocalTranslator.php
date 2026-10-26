<?php

namespace App\Services\Translation;

use App\Exceptions\TranslationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Translates on our own server: the "translator" container (NLLB-200 on
 * CTranslate2). The text never leaves the server.
 */
class LocalTranslator implements Translator
{
    private const TEXTS_PER_REQUEST = 64;

    /**
     * @param  list<string>  $texts
     * @return list<string>
     *
     * @throws ConnectionException|RequestException when the service is down or fails; worth retrying
     */
    public function translate(array $texts, string $from, string $to): array
    {
        $source = Languages::nllb($from) ?? throw new TranslationException("Translating from this language isn't supported yet.");
        $target = Languages::nllb($to) ?? throw new TranslationException("Translating into this language isn't supported yet.");

        $translated = [];

        // The service takes up to 128 texts per request (Docker/translator/server.py)
        foreach (array_chunk($texts, self::TEXTS_PER_REQUEST) as $chunk) {
            $translations = Http::baseUrl(config('translation.local.url'))
                ->timeout(config('translation.local.timeout'))
                ->acceptJson()
                ->post('/translate', ['source' => $source, 'target' => $target, 'texts' => $chunk])
                ->throw()
                ->json('translations');

            if (! is_array($translations) || count($translations) !== count($chunk)) {
                throw new RuntimeException('The translator returned a different number of translations than texts sent.');
            }

            array_push($translated, ...array_values($translations));
        }

        return $translated;
    }
}
