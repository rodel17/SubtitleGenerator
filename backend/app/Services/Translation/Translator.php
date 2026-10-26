<?php

namespace App\Services\Translation;

use App\Exceptions\TranslationException;

/**
 * A machine translation engine. Languages are the app's codes (see Languages).
 */
interface Translator
{
    /**
     * Translate each text from one language into another, keeping the order.
     *
     * @param  list<string>  $texts
     * @return list<string>
     *
     * @throws TranslationException when a language isn't supported
     */
    public function translate(array $texts, string $from, string $to): array;
}
