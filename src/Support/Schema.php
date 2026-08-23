<?php

declare(strict_types=1);

namespace ScoutFts5\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use ScoutFts5\Exceptions\ScoutFts5Exception;
use ScoutFts5\SearchConfiguration;

/**
 * Owns everything about the shape of an FTS5 index table.
 *
 * Each searchable model maps to one virtual table holding a single `content`
 * column, plus one `UNINDEXED` column per declared filter. Models with an
 * integer key store the key in the table's implicit `rowid`, which makes
 * updates and deletes an index lookup instead of a full scan. Models with a
 * string key (UUID, ULID) get an explicit `doc_id` column instead, and pay a
 * scan on write — FTS5 has nowhere to put a secondary index.
 */
class Schema
{
    /**
     * The column holding the document key for models with a string key.
     */
    public const DOCUMENT_COLUMN = 'doc_id';

    /**
     * The column holding the indexed text of a document.
     */
    public const CONTENT_COLUMN = 'content';

    /**
     * The filter column Scout uses to keep trashed models out of results.
     */
    public const SOFT_DELETE_COLUMN = '__soft_deleted';

    public function __construct(
        private ConnectionInterface $connection,
        private SearchConfiguration $configuration,
    ) {}

    /**
     * The name of the virtual table backing the given model.
     */
    public function tableFor(Model $model): string
    {
        return $this->tableName($model->searchableAs());
    }

    /**
     * The name of the virtual table backing the given Scout index name.
     */
    public function tableName(string $index): string
    {
        return $index.$this->configuration->suffix();
    }

    /**
     * Whether the model's key is stored in the table's implicit `rowid`.
     */
    public function usesRowId(Model $model): bool
    {
        return in_array($model->getKeyType(), ['int', 'integer'], true);
    }

    /**
     * The column a document is addressed by, for use in `where` and `select`.
     */
    public function documentColumn(Model $model): string
    {
        return $this->usesRowId($model) ? 'rowid' : self::DOCUMENT_COLUMN;
    }

    /**
     * The filter columns of the given model, in table order.
     *
     * These come from the model's `searchableFilters()` method, plus Scout's
     * soft delete flag when soft delete support is turned on.
     *
     * @return string[]
     */
    public function filterColumnsFor(Model $model): array
    {
        $columns = [];

        if (method_exists($model, 'searchableFilters')) {
            $columns = array_keys($model->searchableFilters());
        }

        if ($this->modelUsesSoftDeletes($model)) {
            $columns[] = self::SOFT_DELETE_COLUMN;
        }

        return array_values(array_unique(array_map(
            fn (string $column) => $this->validateIdentifier($column),
            $columns
        )));
    }

    /**
     * Whether trashed models are indexed with a soft delete flag.
     */
    public function modelUsesSoftDeletes(Model $model): bool
    {
        return config('scout.soft_delete', false)
            && in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }

    /**
     * Whether the given table already exists on the connection.
     */
    public function exists(string $table): bool
    {
        $found = $this->connection->selectOne(
            "SELECT COUNT(*) AS total FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$this->physical($table)]
        );

        return ((array) $found)['total'] > 0;
    }

    /**
     * The columns of an existing index table, excluding the implicit `rowid`.
     *
     * @return string[]
     */
    public function columns(string $table): array
    {
        $columns = $this->connection->select('PRAGMA table_info('.$this->reference($table).')');

        return array_map(fn ($column) => ((array) $column)['name'], $columns);
    }

    /**
     * Whether an existing index table still has every column the model needs.
     *
     * Filter columns are part of the table definition, so adding one to a
     * model means the table has to be rebuilt — FTS5 has no `ALTER TABLE`.
     */
    public function matchesModel(Model $model): bool
    {
        $table = $this->tableFor($model);

        if (! $this->exists($table)) {
            return false;
        }

        $expected = $this->filterColumnsFor($model);
        $expected[] = self::CONTENT_COLUMN;

        if (! $this->usesRowId($model)) {
            $expected[] = self::DOCUMENT_COLUMN;
        }

        return array_diff($expected, $this->columns($table)) === [];
    }

    /**
     * Creates the virtual table for the given model unless it already exists.
     * Returns whether a table was actually created.
     */
    public function createFor(Model $model): bool
    {
        return $this->create(
            $this->tableFor($model),
            $this->filterColumnsFor($model),
            $this->usesRowId($model)
        );
    }

    /**
     * Creates a virtual table with the given filter columns unless it already
     * exists. Returns whether a table was actually created.
     *
     * @param  string[]  $filterColumns
     */
    public function create(string $table, array $filterColumns = [], bool $rowId = true): bool
    {
        if ($this->exists($table)) {
            return false;
        }

        $columns = $rowId ? [] : [$this->quote(self::DOCUMENT_COLUMN).' UNINDEXED'];
        $columns[] = $this->quote(self::CONTENT_COLUMN);

        foreach ($filterColumns as $column) {
            $columns[] = $this->quote($this->validateIdentifier($column)).' UNINDEXED';
        }

        $columns[] = "tokenize='".str_replace("'", "''", $this->configuration->tokenizer())."'";

        $this->connection->statement(sprintf(
            'CREATE VIRTUAL TABLE %s USING fts5(%s)',
            $this->reference($table),
            implode(', ', $columns)
        ));

        return true;
    }

    /**
     * Drops the given table if it exists. Returns whether it was dropped.
     */
    public function drop(string $table): bool
    {
        if (! $this->exists($table)) {
            return false;
        }

        $this->connection->statement('DROP TABLE '.$this->reference($table));

        return true;
    }

    /**
     * Merges the FTS5 b-tree into as few segments as possible. Worth running
     * after a bulk import; pointless after a handful of writes.
     */
    public function optimize(string $table): void
    {
        if (! $this->exists($table)) {
            return;
        }

        $reference = $this->reference($table);

        $this->connection->statement(
            "INSERT INTO {$reference}({$reference}) VALUES('optimize')"
        );
    }

    /**
     * How an index table is referred to in raw SQL: quoted, and carrying the
     * connection's table prefix.
     *
     * The query builder adds that prefix itself, so it is given plain table
     * names while every hand-written fragment — `CREATE`, `MATCH`, `bm25()`,
     * `PRAGMA` — goes through here. Getting this wrong only shows up on
     * connections that set a prefix, which is exactly when it is hardest to
     * debug.
     */
    public function reference(string $table): string
    {
        return $this->quote($this->physical($table));
    }

    /**
     * The name the table actually has in the database file.
     */
    public function physical(string $table): string
    {
        return $this->connection->getTablePrefix().$table;
    }

    /**
     * Quotes an SQLite identifier.
     */
    public function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    /**
     * Guards against filter column names that would change the meaning of the
     * `CREATE VIRTUAL TABLE` statement they are interpolated into.
     */
    private function validateIdentifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new ScoutFts5Exception(
                "[{$identifier}] is not a valid FTS5 filter column name. Use letters, digits and underscores only."
            );
        }

        return $identifier;
    }
}
