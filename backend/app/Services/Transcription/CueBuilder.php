<?php

namespace App\Services\Transcription;

/**
 * Turns Whisper segments (often a whole sentence, up to 30 seconds) into
 * subtitle cues a viewer can read: at most two lines of 42 characters and
 * 7 seconds each, broken at sentence ends and, once a line is full, at commas.
 */
class CueBuilder
{
    public const MAX_LINE_LENGTH = 42;

    public const MAX_LINES = 2;

    public const MAX_DURATION = 7.0;

    /**
     * @param  list<array{start: float, end: float, text: string, words?: list<array{start: float, end: float, word: string}>}>  $segments
     * @return list<array{start: float, end: float, text: string}>
     */
    public function build(array $segments): array
    {
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

            if ($this->endsSentence($token) || ($this->endsClause($token) && mb_strlen($this->textOf($current)) >= self::MAX_LINE_LENGTH)) {
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
        return mb_strlen($this->textOf($words)) > self::MAX_LINE_LENGTH * self::MAX_LINES
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
        return implode(' ', array_column($words, 'token'));
    }

    /**
     * @param  non-empty-list<array{start: float, end: float, token: string}>  $words
     * @return array{start: float, end: float, text: string}
     */
    private function cueFromWords(array $words): array
    {
        return $this->cue($words[0]['start'], end($words)['end'], $this->textOf($words));
    }

    /**
     * @return array{start: float, end: float, text: string}
     */
    private function cue(float $start, float $end, string $text): array
    {
        return ['start' => $start, 'end' => $end, 'text' => $this->wrap($text)];
    }

    /**
     * Split text longer than one line at the space that makes the two lines most even.
     */
    private function wrap(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_LINE_LENGTH) {
            return $text;
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

    private function endsSentence(string $token): bool
    {
        return (bool) preg_match('/[.!?…。！？]["\')\]]*$/u', $token);
    }

    private function endsClause(string $token): bool
    {
        return (bool) preg_match('/[,;:、，]["\')\]]*$/u', $token);
    }
}
