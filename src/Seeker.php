<?php

declare(strict_types=1);

namespace ScoutFts5;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Laravel\Scout\Builder;
use ScoutFts5\Contracts\Normalizer;
use ScoutFts5\Exceptions\ScoutFts5Exception;
use ScoutFts5\Support\MatchQuery;
use ScoutFts5\Support\Schema;
use ScoutFts5\Support\SearchPass;
use ScoutFts5\Support\Tokens;

/**
 * Runs searches against an FTS5 index table.
 *
 * A query is answered by a cascade of passes, from strictest to loosest — see
 * {@see SearchPass}. Ordering and pagination both happen in SQL, so the page
 * of keys that comes back is the page the caller asked for rather than a slice
 * of an arbitrary order.
 */
class Seeker
{
    /**
     * The alias the model's own table is joined under when a search needs
     * columns that are not in the index.
     */
    private const MODEL_ALIAS = 'scout_fts5_model';

    /**
     * Cached column listings of model tables, keyed by table name.
     *
     * @var array<string, string[]>
     */
    private array $modelColumns = [];

    public function __construct(
        private ConnectionInterface $connection,
        private Schema $schema,
        private Normalizer $normalizer,
        private SearchConfiguration $configuration,
    ) {}

    /**
     * Performs the search described by the given builder.
     *
     * Passing `$perPage` switches the search to pagination: the returned keys
     * are one page long, while the total reports every document that matched.
     */
    public function search(Builder $builder, int $page = 1, ?int $perPage = null): SearchResult
    {
        $model = $builder->model;
        $table = $this->schema->tableFor($model);

        if (! $this->schema->exists($table)) {
            return SearchResult::empty($builder);
        }

        $words = Tokens::words($this->normalizer->normalize((string) $builder->query));

        if ($words === []) {
            return SearchResult::empty($builder);
        }

        $limit = $perPage ?? $builder->limit;
        $offset = $perPage !== null ? (max($page, 1) - 1) * $perPage : 0;

        foreach ($this->passes($words, $table) as $pass) {
            $total = $this->newQuery($builder, $table, $pass)->count();

            if ($total === 0) {
                continue;
            }

            $query = $this->newQuery($builder, $table, $pass)
                ->select(new Expression($this->documentExpression($model, $table).' as scout_fts5_key'));

            $this->applyOrder($query, $builder, $pass);

            if ($limit !== null) {
                $query->offset($offset)->limit($limit);
            }

            return new SearchResult($builder, $query->pluck('scout_fts5_key')->all(), $total, $pass->name);
        }

        return SearchResult::empty($builder);
    }

    /**
     * Builds the passes for the given words, in the order they are attempted.
     *
     * Passes turned off in the configuration are left out, as are passes that
     * would only repeat the query an earlier pass already ran.
     *
     * @param  string[]  $words
     * @return SearchPass[]
     */
    private function passes(array $words, string $table): array
    {
        $passes = [];

        if ($this->configuration->passEnabled('prefix')) {
            $passes[] = SearchPass::match('prefix', $table, MatchQuery::all($words), $this->schema);
        }

        if ($this->configuration->passEnabled('typo')) {
            $truncated = array_map(
                fn (string $word) => Tokens::truncate(
                    $word,
                    $this->configuration->typoTrim(),
                    $this->configuration->typoMinPrefix()
                ),
                $words
            );

            // Words too short to shorten come back unchanged, in which case
            // this pass would repeat the prefix pass.
            if ($truncated !== $words) {
                $passes[] = SearchPass::match('typo', $table, MatchQuery::all($truncated), $this->schema);
            }
        }

        // With a single word, "every word must match" and "any word may match"
        // are the same query.
        if ($this->configuration->passEnabled('any') && count($words) > 1) {
            $passes[] = SearchPass::match('any', $table, MatchQuery::any($words), $this->schema);
        }

        if ($this->configuration->passEnabled('trigram')) {
            $gramGroups = Tokens::gramGroups(
                $words,
                $this->configuration->trigramSize(),
                $this->configuration->trigramMaxGrams()
            );

            if ($gramGroups !== []) {
                $passes[] = SearchPass::substring(
                    'trigram',
                    $table,
                    $gramGroups,
                    $this->schema,
                    $this->configuration->trigramMinRatio()
                );
            }
        }

        return $passes;
    }

    /**
     * Builds a query for one pass, with the builder's filters applied but
     * without ordering or pagination.
     */
    private function newQuery(Builder $builder, string $table, SearchPass $pass): QueryBuilder
    {
        $query = $this->connection->table($table);

        $pass->constrain($query);

        $this->applyJoin($query, $builder, $table);
        $this->applyFilters($query, $builder, $table);

        return $query;
    }

    /**
     * Joins the model's own table when the search needs columns that live
     * there — an explicit ordering, or a filter on a column that is not part
     * of the index.
     */
    private function applyJoin(QueryBuilder $query, Builder $builder, string $table): void
    {
        if (! $this->needsModelTable($builder)) {
            return;
        }

        $model = $builder->model;

        $query->join(
            $model->getTable().' as '.self::MODEL_ALIAS,
            self::MODEL_ALIAS.'.'.$model->getScoutKeyName(),
            '=',
            new Expression($this->documentExpression($model, $table))
        );
    }

    /**
     * Applies the builder's `where`, `whereIn` and `whereNotIn` constraints.
     */
    private function applyFilters(QueryBuilder $query, Builder $builder, string $table): void
    {
        foreach ($builder->wheres as $where) {
            $query->where(
                $this->qualify($builder, $table, $where['field']),
                $where['operator'],
                $where['value']
            );
        }

        foreach ($builder->whereIns as $field => $values) {
            $query->whereIn($this->qualify($builder, $table, $field), $values);
        }

        foreach ($builder->whereNotIns as $field => $values) {
            $query->whereNotIn($this->qualify($builder, $table, $field), $values);
        }
    }

    /**
     * Orders results by relevance, or by the builder's own ordering when one
     * was given.
     *
     * This has to happen in SQL rather than after hydrating models, because
     * the order is what decides which documents land on which page.
     */
    private function applyOrder(QueryBuilder $query, Builder $builder, SearchPass $pass): void
    {
        if ($builder->orders === []) {
            $pass->rank($query);

            return;
        }

        foreach ($builder->orders as $order) {
            $query->orderBy(self::MODEL_ALIAS.'.'.$order['column'], $order['direction']);
        }
    }

    /**
     * Resolves a filter field to the table that can answer it.
     *
     * @throws ScoutFts5Exception
     */
    private function qualify(Builder $builder, string $table, string $field): string
    {
        $model = $builder->model;

        if (in_array($field, $this->schema->filterColumnsFor($model), true)) {
            return $table.'.'.$field;
        }

        if (in_array($field, $this->columnsOf($model), true)) {
            return self::MODEL_ALIAS.'.'.$field;
        }

        throw ScoutFts5Exception::unknownFilter(
            $field,
            $model::class,
            array_merge($this->schema->filterColumnsFor($model), $this->columnsOf($model))
        );
    }

    /**
     * Whether this search has to reach into the model's own table.
     */
    private function needsModelTable(Builder $builder): bool
    {
        if ($builder->orders !== []) {
            return true;
        }

        $indexed = $this->schema->filterColumnsFor($builder->model);

        foreach ($this->filteredFields($builder) as $field) {
            if (! in_array($field, $indexed, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every field the builder filters on, however the constraint was added.
     *
     * @return string[]
     */
    private function filteredFields(Builder $builder): array
    {
        return array_merge(
            array_column($builder->wheres, 'field'),
            array_keys($builder->whereIns),
            array_keys($builder->whereNotIns)
        );
    }

    /**
     * The columns of the model's own table, resolved once per instance.
     *
     * @return string[]
     */
    private function columnsOf(Model $model): array
    {
        return $this->modelColumns[$model->getTable()] ??= $this->connection
            ->getSchemaBuilder()
            ->getColumnListing($model->getTable());
    }

    /**
     * The expression addressing a document's key in the index table.
     */
    private function documentExpression(Model $model, string $table): string
    {
        return $this->schema->reference($table).'.'.$this->schema->quote(
            $this->schema->documentColumn($model)
        );
    }
}
