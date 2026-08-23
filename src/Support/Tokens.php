<?php

declare(strict_types=1);

namespace Mtk3d\Scout\Fts5\Support;

/**
 * Splits normalized text into the words and substrings the search passes need.
 */
class Tokens
{
    /**
     * Splits text into words. Everything that is not a unicode letter or digit
     * separates words, which matches how the `unicode61` tokenizer treats
     * punctuation on the FTS5 side.
     *
     * @return string[]
     */
    public static function words(string $text): array
    {
        $words = preg_split("/[^\p{L}\p{N}]+/u", $text, -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : $words;
    }

    /**
     * Cuts `$trim` characters off the end of a word, never going below
     * `$minimum` characters. Used to shrug off typos in word endings.
     */
    public static function truncate(string $word, int $trim, int $minimum): string
    {
        $length = max($minimum, mb_strlen($word) - $trim);

        return mb_substr($word, 0, $length);
    }

    /**
     * Builds the substrings of each word separately, so a pass can ask how
     * much of a *word* matched rather than how much of the whole query.
     *
     * Words shorter than the substring size are skipped: their substring is
     * the word itself, which would match almost anything. The total number of
     * substrings is capped at `$max`.
     *
     * @param  string[]  $words
     * @return array<int, string[]>
     */
    public static function gramGroups(array $words, int $size, int $max): array
    {
        $groups = [];
        $budget = $max;

        foreach ($words as $word) {
            $length = mb_strlen($word);

            if ($length < $size || $budget <= 0) {
                continue;
            }

            $grams = [];

            for ($offset = 0; $offset <= $length - $size; $offset++) {
                $grams[] = mb_substr($word, $offset, $size);
            }

            $grams = array_slice(array_values(array_unique($grams)), 0, $budget);

            $groups[] = $grams;
            $budget -= count($grams);
        }

        return $groups;
    }
}
