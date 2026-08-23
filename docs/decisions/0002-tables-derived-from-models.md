# 2. Derive index tables from models, not migrations

**Status:** accepted

## Context

The implementation this package grew out of created its FTS5 tables in a migration with the table names written out by hand:

```php
foreach (['customers', 'orders', 'invoices', 'products', 'tire_storages'] as $table) {
    DB::statement("CREATE VIRTUAL TABLE {$table}_fts USING fts5(...)");
}
```

That works in the application it was written for and nowhere else. A published package cannot ship a list of somebody else's tables, and a migration the user has to hand-edit after every `vendor:publish` is a poor substitute.

## Decision

Take the table name from `searchableAs()`, the filter columns from `searchableFilters()`, and create tables from the models themselves — through `scout:fts5-create`, or on demand at the first write when `auto_create` is on.

## Consequences

There is no migration to publish and nothing about the index in version control. The model is the single declaration of what is searchable.

Because FTS5 has no `ALTER TABLE`, a table whose columns no longer match its model cannot be migrated in place. The driver detects the mismatch on write and raises an error naming the rebuild command, rather than letting the insert fail on SQLite's `no such column`.

`scout:fts5-rebuild` is therefore a normal part of the workflow rather than a recovery tool, and the commands discover models by scanning the configured paths so none of this needs a list maintained by hand.

`Engine::createIndex()` receives only a name from Scout's own `scout:index`, with no model behind it, so it creates a table with no filter columns. Models that declare filters must be created through this package's commands, which can see the model.
