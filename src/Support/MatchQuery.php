<?php

declare(strict_types=1);

namespace Mtk3d\Scout\Fts5\Support;

/**
 * Builds FTS5 `MATCH` expressions out of user input.
 *
 * Every word is emitted as a quoted FTS5 string so that query syntax the user
 * happened to type — `AND`, `NEAR`, `-`, `(`, `"` — is searched for literally
 * instead of being executed. This is the injection boundary of the package:
 * the match expression itself is still bound as a statement parameter, but
 * FTS5 parses its contents as a query language of its own.
 *
 * @see https://www.sqlite.org/fts5.html#full_text_query_syntax
 */
class MatchQuery
{
    /**
     * Quotes a single word as an FTS5 phrase, optionally as a prefix query.
     */
    public static function phrase(string $word, bool $prefix = false): string
    {
        $quoted = '"'.str_replace('"', '""', $word).'"';

        return $prefix ? $quoted.'*' : $quoted;
    }

    /**
     * Requires every word to be present, each matched as a prefix.
     *
     * @param  string[]  $words
     */
    public static function all(array $words, bool $prefix = true): string
    {
        return implode(' AND ', array_map(
            fn (string $word) => self::phrase($word, $prefix),
            $words
        ));
    }

    /**
     * Requires at least one of the words to be present.
     *
     * @param  string[]  $words
     */
    public static function any(array $words, bool $prefix = true): string
    {
        return implode(' OR ', array_map(
            fn (string $word) => self::phrase($word, $prefix),
            $words
        ));
    }
}
