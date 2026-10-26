<?php

namespace App\Services\Translation;

use App\Exceptions\TranslationException;

/**
 * Translates a Whisper transcript segment by segment (whole sentences, so meaning
 * isn't cut at cue breaks). Translated text has no word timings of its own, so
 * each segment's time is spread over its translated words in proportion to their
 * length; CueBuilder then cuts cues as it does for speech.
 */
class TranscriptTranslator
{
    /**
     * Longest text sent as one piece. NLLB tends to drop the end of longer input
     * (seen with Chinese), so longer sentences are sent clause by clause.
     */
    private const LONGEST_INPUT = 100;

    /**
     * Longest piece of text without spaces given its own timing: Japanese pieces
     * usually end earlier, where a new word starts; Chinese words are short.
     */
    private const LONGEST_PIECE = ['ja' => 10, 'zh' => 4, 'yue' => 4];

    public function __construct(private Translator $translator) {}

    /**
     * The same, translating with another engine.
     */
    public function using(Translator $translator): static
    {
        return new static($translator);
    }

    /**
     * Whether the transcript is in another language than $to (or its language is unknown).
     *
     * @param  array{language?: string|null}  $transcript
     */
    public function needsTranslation(array $transcript, string $to): bool
    {
        return ! Languages::same($transcript['language'] ?? null, $to);
    }

    /**
     * @param  array{language?: string|null, segments: list<array{start: float, end: float, text: string}>}  $transcript
     * @param  (callable(float $fraction): void)|null  $onProgress
     * @return array{language: string, segments: list<array{start: float, end: float, text: string, words: list<array{start: float, end: float, word: string}>}>}
     *
     * @throws TranslationException when the spoken language is unknown or can't be translated
     */
    public function translate(array $transcript, string $to, ?callable $onProgress = null): array
    {
        $from = $transcript['language'] ?? null;

        if (Languages::normalize($from) === null) {
            throw new TranslationException("We couldn't tell which language is spoken, so the subtitles can't be translated. Choose the spoken language and upload the file again.");
        }

        $batches = array_chunk($transcript['segments'], max(1, (int) config('translation.batch_size')));
        $segments = [];

        foreach ($batches as $number => $batch) {
            // Each segment as one or more pieces; all pieces of the batch go in one request
            $pieces = array_map(fn (array $segment): array => $this->inputPieces($segment['text'], $from), $batch);
            $translated = $this->translator->translate(array_merge(...$pieces), $from, $to);
            $separator = Languages::writtenWithoutSpaces($to) ? '' : ' ';

            foreach ($pieces as $index => $segmentPieces) {
                $text = trim(implode($separator, array_filter(array_map(trim(...), array_splice($translated, 0, count($segmentPieces))))));

                if ($text !== '') {
                    $start = (float) $batch[$index]['start'];
                    $end = (float) $batch[$index]['end'];
                    $segments[] = ['start' => $start, 'end' => $end, 'text' => $text, 'words' => $this->timeWords($text, $start, $end, $to)];
                }
            }

            if ($onProgress !== null) {
                $onProgress(($number + 1) / count($batches));
            }
        }

        return ['language' => $to, 'segments' => $segments];
    }

    /**
     * A segment's text as pieces the model translates whole: its sentences, and
     * sentences longer than LONGEST_INPUT cut at commas (clauses packed up to the limit).
     *
     * @return list<string>
     */
    private function inputPieces(string $text, ?string $language): array
    {
        $separator = Languages::writtenWithoutSpaces($language) ? '' : ' ';
        $pieces = [];

        foreach (preg_split('/(?<=[.!?…。！？])\s*/u', trim($text), flags: PREG_SPLIT_NO_EMPTY) as $sentence) {
            if (mb_strlen($sentence) <= self::LONGEST_INPUT) {
                $pieces[] = $sentence;

                continue;
            }

            $current = '';

            foreach (preg_split('/(?<=[,;:，、；：])\s*/u', $sentence, flags: PREG_SPLIT_NO_EMPTY) as $clause) {
                if ($current !== '' && mb_strlen($current.$separator.$clause) > self::LONGEST_INPUT) {
                    $pieces[] = $current;
                    $current = $clause;
                } else {
                    $current = $current === '' ? $clause : $current.$separator.$clause;
                }
            }

            $pieces[] = $current;
        }

        return $pieces === [] ? [''] : $pieces;
    }

    /**
     * Give each word of the translation a share of the segment's time, by length.
     *
     * @return list<array{start: float, end: float, word: string}>
     */
    private function timeWords(string $text, float $start, float $end, string $language): array
    {
        $tokens = Languages::writtenWithoutSpaces($language) ? $this->pieces($text, $language) : preg_split('/\s+/u', $text, flags: PREG_SPLIT_NO_EMPTY);
        $total = array_sum(array_map(mb_strlen(...), $tokens));
        $words = [];
        $position = 0;

        foreach ($tokens as $token) {
            $from = $start + ($end - $start) * $position / $total;
            $position += mb_strlen($token);
            $words[] = [
                'start' => round($from, 3),
                'end' => round($start + ($end - $start) * $position / $total, 3),
                'word' => $token,
            ];
        }

        return $words;
    }

    /**
     * Text without spaces cut into word-like pieces, so cues and lines don't break
     * inside a word: after punctuation, where Japanese hiragana (particles, endings)
     * is followed by kanji or katakana (a new word), and at most every few characters.
     *
     * @return list<string>
     */
    private function pieces(string $text, string $language): array
    {
        $longest = self::LONGEST_PIECE[Languages::normalize($language)] ?? 4;
        $pieces = [];
        $current = '';
        $previous = '';

        foreach (mb_str_split(preg_replace('/\s+/u', '', $text)) as $character) {
            // Punctuation always ends the piece before it (checked first: PCRE counts 、 and 。 as kanji and kana too)
            $isPunctuation = (bool) preg_match('/[、。，！？,.!?…：；:;]/u', $character);
            $startsWord = ! $isPunctuation
                && preg_match('/\p{Hiragana}/u', $previous)
                && preg_match('/[\p{Han}\p{Katakana}]/u', $character);

            if ($current !== '' && ! $isPunctuation && ($startsWord || mb_strlen($current) >= $longest)) {
                $pieces[] = $current;
                $current = '';
            }

            $current .= $character;
            $previous = $character;

            if ($isPunctuation) {
                $pieces[] = $current;
                $current = '';
            }
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }
}
