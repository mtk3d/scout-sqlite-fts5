<?php

declare(strict_types=1);

namespace ScoutFts5;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine as ScoutEngine;
use ScoutFts5\Support\Schema;

/**
 * A Laravel Scout engine backed by SQLite's FTS5 full-text index.
 *
 * The index lives in the same SQLite database as the models themselves, in one
 * virtual table per searchable model. Nothing else has to run: no daemon, no
 * API key, no separate schema to keep in sync.
 */
class Engine extends ScoutEngine
{
    public function __construct(
        private Indexer $indexer,
        private Seeker $seeker,
        private Schema $schema,
    ) {}

    /**
     * Adds the given models to the index, replacing anything indexed for them
     * before.
     *
     * @param  EloquentCollection<int, Model>  $models
     */
    public function update($models): void
    {
        $this->indexer->index($models);
    }

    /**
     * Removes the given models from the index.
     *
     * @param  EloquentCollection<int, Model>  $models
     */
    public function delete($models): void
    {
        $this->indexer->delete($models);
    }

    /**
     * Performs the given search.
     */
    public function search(Builder $builder): SearchResult
    {
        return $this->seeker->search($builder);
    }

    /**
     * Performs the given search, returning a single page of results.
     *
     * @param  int  $perPage
     * @param  int  $page
     */
    public function paginate(Builder $builder, $perPage, $page): SearchResult
    {
        return $this->seeker->search($builder, (int) $page, (int) $perPage);
    }

    /**
     * Returns the keys of the matching documents, best match first.
     *
     * @param  SearchResult  $results
     */
    public function mapIds($results): Collection
    {
        return Collection::make($results->ids());
    }

    /**
     * Hydrates the matching documents into models, keeping the order the
     * search put them in.
     *
     * @param  SearchResult  $results
     * @param  Model  $model
     */
    public function map(Builder $builder, $results, $model): EloquentCollection
    {
        $ids = $results->ids();

        if ($ids === []) {
            return $model->newCollection();
        }

        $positions = array_flip(array_map(strval(...), $ids));

        return $model->getScoutModelsByIds($builder, $ids)
            ->filter(fn (Model $model) => isset($positions[(string) $model->getScoutKey()]))
            ->sortBy(fn (Model $model) => $positions[(string) $model->getScoutKey()])
            ->values();
    }

    /**
     * The lazy counterpart of {@see map()}.
     *
     * @param  SearchResult  $results
     * @param  Model  $model
     */
    public function lazyMap(Builder $builder, $results, $model): LazyCollection
    {
        $ids = $results->ids();

        if ($ids === []) {
            return LazyCollection::empty();
        }

        $positions = array_flip(array_map(strval(...), $ids));

        return $model->queryScoutModelsByIds($builder, $ids)
            ->cursor()
            ->filter(fn (Model $model) => isset($positions[(string) $model->getScoutKey()]))
            ->sortBy(fn (Model $model) => $positions[(string) $model->getScoutKey()])
            ->values();
    }

    /**
     * The number of documents that matched, ignoring pagination.
     *
     * @param  SearchResult  $results
     */
    public function getTotalCount($results): int
    {
        return $results->total();
    }

    /**
     * Empties the index of the given model without dropping the table.
     *
     * @param  Model  $model
     */
    public function flush($model): void
    {
        $this->indexer->flush($model);
    }

    /**
     * Creates an index table by name, as `scout:index` does.
     *
     * Only the name is available here, so the table is created with no filter
     * columns. Models that declare `searchableFilters()` should be created
     * with `scout:fts5-create`, which can see the model.
     *
     * @param  string  $name
     */
    public function createIndex($name, array $options = []): void
    {
        $this->schema->create(
            $this->schema->tableName($name),
            $options['filters'] ?? [],
            $options['rowid'] ?? true
        );
    }

    /**
     * Drops the index table with the given name.
     *
     * @param  string  $name
     */
    public function deleteIndex($name): void
    {
        $this->schema->drop($this->schema->tableName($name));
    }
}
