<?php

namespace App\Services\Transcription;

use App\Services\Translation\Languages;

/**
 * Turns Whisper segments (often a whole sentence, up to 30 seconds) into
 * subtitle cues a viewer can read: at most two lines of 42 characters and
 * 7 seconds each, broken at sentence ends and, once a line is full, at commas.
 * Languages written without spaces (Japanese, Chinese) get 16-character lines
 * and their words are joined without spaces.
 */
class CueBuilder
{
    public const MAX_LINE_LENGTH = 42;

    public const MAX_LINE_LENGTH_WITHOUT_SPACES = 16;

    public const MAX_LINES = 2;

    public const MAX_DURATION = 7.0;

    /** Set per build() call from the language */
    private int $maxLineLength = self::MAX_LINE_LENGTH;

    private string $wordSeparator = ' ';

    /**
     * @param  list<array{start: float, end: float, text: string, words?: list<array{start: float, end: float, word: string}>}>  $segments
     * @param  string|null  $language  of the text (app or Whisper code); decides spacing and line length
     * @return list<array{start: float, end: float, text: string}>
     */
    public function build(array $segments, ?string $language = null): array
    {
        $withoutSpaces = Languages::writtenWithoutSpaces($language);
        $this->maxLineLength = $withoutSpaces ? self::MAX_LINE_LENGTH_WITHOUT_SPACES : self::MAX_LINE_LENGTH;
        $this->wordSeparator = $withoutSpaces ? '' : ' ';
        $cues = [];

        foreach ($segments as $segment) {
            $words = $segment['words'] ?? [];

            if ($words === []) {
                // No word timings: keep the segment whole, but still wrap its lines
                $cues[] = $this->cue($segment['start'], $segment['end'], trim($segment['text']));

                continue;
            }

            array_push($cues, ...$this->splitWords($words));
        }

        return $cues;
    }

    /**
     * @param  list<array{start: float, end: float, word: string}>  $words
     * @return list<array{start: float, end: float, text: string}>
     */
    private function splitWords(array $words): array
    {
        $cues = [];
        /** @var list<array{start: float, end: float, token: string}> $current */
        $current = [];

        foreach ($words as $word) {
            $token = trim($word['word']);

            if ($token === '') {
                continue;
            }

            $next = ['start' => $word['start'], 'end' => $word['end'], 'token' => $token];

            if ($current !== [] && $this->exceedsLimits([...$current, $next])) {
                // Prefer ending the cue at its last comma; the words after it start the next cue
                $cut = $this->lastClauseEnd($current) ?? count($current) - 1;
                $cues[] = $this->cueFromWords(array_slice($current, 0, $cut + 1));
                $current = array_slice($current, $cut + 1);
            }

            $current[] = $next;

            if ($this->endsSentence($token) || ($this->endsClause($token) && mb_strlen($this->textOf($current)) >= $this->maxLineLength)) {
                $cues[] = $this->cueFromWords($current);
                $current = [];
            }
        }

        if ($current !== []) {
            $cues[] = $this->cueFromWords($current);
        }

        return $cues;
    }

    /**
     * @param  list<array{start: float, end: float, token: string}>  $words
     */
    private function exceedsLimits(array $words): bool
    {
        return mb_strlen($this->textOf($words)) > $this->maxLineLength * self::MAX_LINES
            || end($words)['end'] - $words[0]['start'] > self::MAX_DURATION;
    }

    /**
     * Index of the last word in the cue that ends a clause, if it is not the final word.
     *
     * @param  list<array{start: float, end: float, token: string}>  $words
     */
    private function lastClauseEnd(array $words): ?int
    {
        for ($index = count($words) - 2; $index >= 0; $index--) {
            if ($this->endsClause($words[$index]['token'])) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<array{start: float, end: float, token: string}>  $words
     */
    private function textOf(array $words): string
    {
        return implode($this->wordSeparator, array_column($words, 'token'));
    }

    /**
     * @param  non-empty-list<array{start: float, end: float, token: string}>  $words
     * @return array{start: float, end: float, text: string}
     */
    private function cueFromWords(array $words): array
    {
        if ($this->wordSeparator === '') {
            // Without spaces, the words themselves are the only safe places to break a line
            return ['start' => $words[0]['start'], 'end' => end($words)['end'], 'text' => $this->wrapAtWords(array_column($words, 'token'))];
        }

        return $this->cue($words[0]['start'], end($words)['end'], $this->textOf($words));
    }

    /**
     * Join words written without spaces, breaking the line between the two words
     * closest to the middle that keep both lines within the limit.
     *
     * @param  non-empty-list<string>  $tokens
     */
    private function wrapAtWords(array $tokens): string
    {
        $text = implode('', $tokens);
        $length = mb_strlen($text);

        if ($length <= $this->maxLineLength) {
            return $text;
        }

        $middle = $length / 2;
        $best = null;
        $position = 0;

        foreach (array_slice($tokens, 0, -1) as $token) {
            $position += mb_strlen($token);

            if (max($position, $length - $position) <= $this->maxLineLength
                && ($best === null || abs($position - $middle) < abs($best - $middle))) {
                $best = $position;
            }
        }

        return $best === null ? $this->wrap($text) : mb_substr($text, 0, $best)."\n".mb_substr($text, $best);
    }

    /**
     * @return array{start: float, end: float, text: string}
     */
    private function cue(float $start, float $end, string $text): array
    {
        return ['start' => $start, 'end' => $end, 'text' => $this->wrap($text)];
    }

    /**
     * Split text longer than one line where the two lines come out most even: at a
     * space, or (in languages without spaces) after punctuation or in the middle.
     */
    private function wrap(string $text): string
    {
        if (mb_strlen($text) <= $this->maxLineLength) {
            return $text;
        }

        if ($this->wordSeparator === '') {
            return $this->wrapWithoutSpaces($text);
        }

        $middle = mb_strlen($text) / 2;
        $best = null;

        foreach (mb_str_split($text) as $position => $character) {
            if ($character === ' ' && ($best === null || abs($position - $middle) < abs($best - $middle))) {
                $best = $position;
            }
        }

        if ($best === null) {
            return $text;
        }

        return mb_substr($text, 0, $best)."\n".mb_substr($text, $best + 1);
    }

    private function wrapWithoutSpaces(string $text): string
    {
        $length = mb_strlen($text);
        $middle = (int) ceil($length / 2);
        $best = $middle;

        // Prefer breaking right after punctuation near the middle, if both lines stay within the limit
        foreach (mb_str_split($text) as $position => $character) {
            $break = $position + 1;

            if ($break < $length
                && preg_match('/[、。，！？,.!?…：；]/u', $character)
                && max($break, $length - $break) <= $this->maxLineLength
                && abs($break - $middle) <= abs($best - $middle) + 4) {
                $best = $break;
            }
        }

        return mb_substr($text, 0, $best)."\n".mb_substr($text, $best);
    }

    private function endsSentence(string $token): bool
    {
        return (bool) preg_match('/[.!?…。！？]["\')\]]*$/u', $token);
    }

    private function endsClause(string $token): bool
    {
        return (bool) preg_match('/[,;:、，]["\')\]]*$/u', $token);
    }
}
