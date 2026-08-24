<?php

declare(strict_types=1);

namespace ScoutFts5;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use ScoutFts5\Contracts\Normalizer;
use ScoutFts5\Exceptions\ScoutFts5Exception;
use ScoutFts5\Support\Schema;
use Throwable;

/**
 * Writes searchable models into their FTS5 index table.
 *
 * Everything a model returns from `toSearchableArray()` is flattened, joined
 * and normalized into the single `content` column FTS5 tokenizes. Values
 * returned from `searchableFilters()` are stored alongside it in unindexed
 * columns, where they can be filtered on without being searched.
 */
class Indexer
{
    /**
     * How many rows are written per insert statement.
     */
    private const CHUNK_SIZE = 500;

    public function __construct(
        private Connection $connection,
        private Schema $schema,
        private Normalizer $normalizer,
        private SearchConfiguration $configuration,
    ) {}

    /**
     * Indexes the given models, replacing whatever was indexed for them before.
     *
     * @param  Collection<int, Model>|Model[]  $models
     *
     * @throws ScoutFts5Exception
     */
    public function index(Collection|array $models): void
    {
        $models = Collection::make($models);

        if ($models->isEmpty()) {
            return;
        }

        try {
            foreach ($models->groupBy(fn (Model $model) => $model::class) as $group) {
                $this->indexGroup($group);
            }
        } catch (Throwable $e) {
            if ($e instanceof ScoutFts5Exception) {
                throw $e;
            }

            throw new ScoutFts5Exception('Updating the FTS5 search index failed.', 0, $e);
        }
    }

    /**
     * Removes the given models from their index table.
     *
     * @param  Collection<int, Model>|Model[]  $models
     *
     * @throws ScoutFts5Exception
     */
    public function delete(Collection|array $models): void
    {
        $models = Collection::make($models);

        if ($models->isEmpty()) {
            return;
        }

        try {
            foreach ($models->groupBy(fn (Model $model) => $model::class) as $group) {
                /** @var Model $first */
                $first = $group->first();
                $table = $this->schema->tableFor($first);

                if (! $this->schema->exists($table)) {
                    continue;
                }

                $this->deleteKeys($first, $group->map(fn (Model $model): mixed => $model->getScoutKey())->all());
            }
        } catch (Throwable $e) {
            throw new ScoutFts5Exception('Removing documents from the FTS5 search index failed.', 0, $e);
        }
    }

    /**
     * Empties the index table of the given model without dropping it.
     */
    public function flush(Model $model): void
    {
        $table = $this->schema->tableFor($model);

        if (! $this->schema->exists($table)) {
            return;
        }

        $this->connection->statement('DELETE FROM '.$this->schema->reference($table));
    }

    /**
     * Indexes a group of models that all share one index table.
     *
     * @param  Collection<int, Model>  $models
     */
    private function indexGroup(Collection $models): void
    {
        /** @var Model $first */
        $first = $models->first();

        $this->ensureTableExists($first);

        $keyColumn = $this->schema->documentColumn($first);
        $filterColumns = $this->schema->filterColumnsFor($first);

        $rows = [];
        $removals = [];

        foreach ($models as $model) {
            $searchable = $model->toSearchableArray();

            // Scout treats an empty searchable array as "do not index this
            // record", which is how models drop out of results without being
            // deleted. Anything already indexed for them has to go.
            if ($searchable === []) {
                $removals[] = $model->getScoutKey();

                continue;
            }

            $row = [
                $keyColumn => $this->documentKey($model),
                Schema::CONTENT_COLUMN => $this->contentFor($searchable),
            ];

            $filters = $this->filtersFor($model);

            foreach ($filterColumns as $column) {
                $row[$column] = $filters[$column] ?? null;
            }

            $rows[] = $row;
        }

        $keys = array_merge($removals, array_map(fn (array $row) => $row[$keyColumn], $rows));

        $this->connection->transaction(function () use ($first, $keys, $rows) {
            $this->deleteKeys($first, $keys);

            foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
                $this->connection->table($this->schema->tableFor($first))->insert($chunk);
            }
        });
    }

    /**
     * Deletes the given document keys from a model's index table.
     *
     * @param  array<int, mixed>  $keys
     */
    private function deleteKeys(Model $model, array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $column = $this->schema->documentColumn($model);

        foreach (array_chunk($keys, self::CHUNK_SIZE) as $chunk) {
            $this->connection
                ->table($this->schema->tableFor($model))
                ->whereIn($column, $chunk)
                ->delete();
        }
    }

    /**
     * Flattens a searchable array into the single string FTS5 will tokenize.
     *
     * @param  array<string, mixed>  $searchable
     */
    private function contentFor(array $searchable): string
    {
        $values = Arr::flatten($searchable);

        $values = array_filter(
            $values,
            fn ($value) => $value !== null && $value !== '' && ! is_array($value) && ! is_object($value)
        );

        $values = array_map(
            fn ($value) => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
            $values
        );

        return $this->normalizer->normalize(implode(' ', $values));
    }

    /**
     * Collects the filter values to store next to a model's content.
     *
     * @return array<string, mixed>
     */
    private function filtersFor(Model $model): array
    {
        $filters = method_exists($model, 'searchableFilters')
            ? $model->searchableFilters()
            : [];

        if ($this->schema->modelUsesSoftDeletes($model)) {
            $metadata = method_exists($model, 'scoutMetadata') ? $model->scoutMetadata() : [];

            $filters[Schema::SOFT_DELETE_COLUMN] = $metadata[Schema::SOFT_DELETE_COLUMN]
                ?? (method_exists($model, 'trashed') && $model->trashed() ? 1 : 0);
        }

        return array_map(
            fn ($value) => is_bool($value) ? (int) $value : $value,
            $filters
        );
    }

    /**
     * The value stored in the document key column, cast for `rowid` storage.
     */
    private function documentKey(Model $model): mixed
    {
        $key = $model->getScoutKey();

        return $this->schema->usesRowId($model) ? (int) $key : $key;
    }

    /**
     * @throws ScoutFts5Exception
     */
    private function ensureTableExists(Model $model): void
    {
        $table = $this->schema->tableFor($model);

        if ($this->schema->exists($table)) {
            if (! $this->schema->matchesModel($model)) {
                throw ScoutFts5Exception::outdatedIndex($table, $model::class);
            }

            return;
        }

        if (! $this->configuration->shouldAutoCreate()) {
            throw ScoutFts5Exception::missingIndex($table);
        }

        $this->schema->createFor($model);
    }
}
