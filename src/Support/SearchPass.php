<?php

declare(strict_types=1);

namespace Mtk3d\Scout\Fts5\Support;

use Closure;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * One attempt at answering a search query: how to constrain the index table,
 * and how to rank whatever comes back.
 *
 * Passes are tried strictest first and the first one that matches anything
 * wins, so a query that finds an exact hit never pays for the fuzzier
 * interpretations below it.
 */
class SearchPass
{
    private function __construct(
        public readonly string $name,
        private Closure $constraint,
        private Closure $order,
    ) {}

    /**
     * A pass that hands an expression to the FTS5 query parser and ranks the
     * results with BM25, where a lower score is the better match.
     */
    public static function match(string $name, string $table, string $expression, Fts5Schema $schema): self
    {
        $quoted = $schema->reference($table);

        return new self(
            $name,
            fn (QueryBuilder $query) => $query->whereRaw($quoted.' MATCH ?', [$expression]),
            fn (QueryBuilder $query) => $query->orderByRaw("bm25({$quoted}) ASC"),
        );
    }

    /**
     * A pass that looks for the query's substrings anywhere in the content.
     *
     * A document matches when enough of a single word's substrings appear in
     * it — `$ratio` of them, rounded up. Asking for a share of one word rather
     * than of the whole query is what separates a misspelled word from a
     * three-letter coincidence: "kowerlski" still shares most of its
     * substrings with "kowalski", while an unrelated query only ever shares a
     * stray one or two.
     *
     * This is the only pass that cannot use the index — it is a scan, which is
     * why it comes last — and the only one that catches a typo in the middle
     * of a word, where no prefix query can reach.
     *
     * @param  array<int, string[]>  $gramGroups
     */
    public static function substring(
        string $name,
        string $table,
        array $gramGroups,
        Fts5Schema $schema,
        float $ratio = 0.4
    ): self {
        $content = $schema->reference($table).'.'.$schema->quote(Fts5Schema::CONTENT_COLUMN);

        $hit = "(CASE WHEN {$content} LIKE ? THEN 1 ELSE 0 END)";

        $conditions = [];
        $conditionBindings = [];
        $scores = [];
        $scoreBindings = [];

        foreach ($gramGroups as $grams) {
            $threshold = max(1, (int) ceil(count($grams) * $ratio));
            $sum = implode(' + ', array_fill(0, count($grams), $hit));

            $conditions[] = "({$sum}) >= {$threshold}";
            $scores[] = $sum;

            foreach ($grams as $gram) {
                $conditionBindings[] = '%'.$gram.'%';
                $scoreBindings[] = '%'.$gram.'%';
            }
        }

        $where = implode(' OR ', $conditions);
        $score = implode(' + ', $scores);

        return new self(
            $name,
            fn (QueryBuilder $query) => $query->whereRaw("({$where})", $conditionBindings),
            fn (QueryBuilder $query) => $query->orderByRaw("({$score}) DESC", $scoreBindings),
        );
    }

    /**
     * Narrows the given query to the documents this pass matches.
     */
    public function constrain(QueryBuilder $query): void
    {
        ($this->constraint)($query);
    }

    /**
     * Orders the given query by this pass's notion of relevance.
     */
    public function rank(QueryBuilder $query): void
    {
        ($this->order)($query);
    }
}
