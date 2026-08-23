<?php

declare(strict_types=1);

namespace Wrenchr\Scout\Fts5\Exceptions;

use RuntimeException;

class ScoutFts5Exception extends RuntimeException
{
    public static function missingIndex(string $table): self
    {
        return new self(
            "The FTS5 index table [{$table}] does not exist. Run `php artisan scout:fts5-create` "
            .'to create it, or enable `scout-fts5.auto_create`.'
        );
    }

    public static function unsupportedDriver(string $driver): self
    {
        return new self(
            "The scout-sqlite-fts5 driver requires an SQLite connection, but [{$driver}] was configured. "
            .'Point `scout-fts5.connection` at an SQLite connection.'
        );
    }

    public static function outdatedIndex(string $table, string $model): self
    {
        return new self(
            "The FTS5 index table [{$table}] is missing columns declared by [{$model}]. FTS5 tables cannot be "
            ."altered, so the index has to be rebuilt: `php artisan scout:fts5-rebuild {$model}`."
        );
    }

    public static function unknownFilter(string $field, string $model, array $available): self
    {
        $known = $available === [] ? 'none' : implode(', ', $available);

        return new self(
            "Cannot filter search results by [{$field}]: it is not an indexed filter column of [{$model}]. "
            ."Declare it in {$model}::searchableFilters() and rebuild the index. Available columns: {$known}."
        );
    }
}
