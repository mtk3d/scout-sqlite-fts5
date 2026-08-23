# Troubleshooting

[← back to the README](https://github.com/mtk3d/scout-sqlite-fts5#readme)

## Errors this package throws

Every one of them is a `ScoutFts5\Exceptions\ScoutFts5Exception`.

### The FTS5 index table `[…]` does not exist

> Run `php artisan scout:fts5-create` to create it, or enable `scout-fts5.auto_create`.

Exactly what it says: something tried to index a model whose table has not been created, with `auto_create` turned off.

```bash
php artisan scout:fts5-create "App\Models\Customer"
```

Note that **searching** a model with no index table is not an error — it returns no results. A search that has to fail loudly on a missing index is a search that breaks the page for every user the moment a table is dropped.

### The FTS5 index table `[…]` is missing columns declared by `[…]`

> FTS5 tables cannot be altered, so the index has to be rebuilt.

You added a key to `searchableFilters()`, or turned on `scout.soft_delete`, after the table was created. FTS5 has no `ALTER TABLE`, so the column cannot be added in place.

```bash
php artisan scout:fts5-rebuild "App\Models\Customer"
```

The driver checks this on every write rather than letting the insert fail with SQLite's own `no such column`, which says nothing about what to do next.

### Cannot filter search results by `[…]`

> It is not an indexed filter column of `[…]`. Available columns: …

A `where()` names a column that is neither in the index nor on the model's table. Usually a typo — the message lists everything you could have meant.

If the column really should be filterable and lives elsewhere, add it to `toSearchableArray()` as a filter and rebuild. See [filtering](filtering-and-ordering.md#filters).

### Cannot order or filter this search by a column of `[…]`

> Its table lives on connection `[…]` while the index is on `[…]`, and SQLite cannot join across connections.

`scout-fts5.connection` points at a different database from the models. That is a supported arrangement — see [keeping the index in its own file](configuration.md#keeping-the-index-in-its-own-file) — but it limits searches to indexed filter columns and relevance ordering.

Either move the index onto the model's connection, or declare the column in `searchableFilters()` and rebuild. An explicit `orderBy()` cannot be rescued that way; relevance ordering is the only option across connections.

### The scout-sqlite-fts5 driver requires an SQLite connection

> Point `scout-fts5.connection` at an SQLite connection.

`scout-fts5.connection` resolves to a MySQL, Postgres or SQL Server connection. This driver is SQLite-only; it checks at boot rather than failing on the first `CREATE VIRTUAL TABLE`.

Leave the option `null` to use the default connection, or name your SQLite one. If your models genuinely do not live in SQLite, use [`namoshek/laravel-scout-database`](https://github.com/Namoshek/laravel-scout-database) instead.

## Errors SQLite throws

### `no such module: fts5`

Your SQLite build has FTS5 compiled out. Check with:

```bash
php -r 'echo (new PDO("sqlite::memory:"))->exec("CREATE VIRTUAL TABLE t USING fts5(c)") === false ? "missing" : "ok";'
```

It is enabled by default in every mainstream build since SQLite 3.9, so a missing module usually means a hand-compiled `libsqlite3` or an unusual Docker base image. On Alpine, install `sqlite-libs`; on a custom build, add `-DSQLITE_ENABLE_FTS5`.

### `database is locked`

Not specific to this package — it is SQLite's writer lock, and indexing is a write. If it started when you enabled the driver, the fix is usually WAL mode:

```php
// config/database.php
'sqlite' => [
    // …
    'journal_mode' => 'wal',
],
```

Moving indexing off the request also helps: set `scout.queue` to `true`.

## Behaviour that looks like a bug

### Search returns nothing after I changed a setting

Changing `tokenizer`, `normalizer` or `suffix` changes how content was stored, but not the content already stored. The index and the query stop agreeing.

```bash
php artisan scout:fts5-rebuild
```

### Nonsense queries return results

The substring pass is matching on too little. Raise `trigram.min_ratio` toward `0.6`, or turn the pass off entirely:

```php
'trigram' => ['min_ratio' => 0.6],
// or
'passes' => ['trigram' => false],
```

Check what is actually happening first — `Customer::search($term)->raw()->pass()` names the pass that answered.

### A misspelling that used to work stopped working

The mirror image of the above: `min_ratio` too high, or `typo.trim` too low for the ending in question. See [the typo pass](how-it-works.md#2-typo--every-word-shortened).

### Results are not in relevance order

An explicit `orderBy()` — including `latest()` and `oldest()` — replaces relevance ordering. That is Scout's contract, not a quirk of this driver. Drop the ordering to get BM25 order back.

Note that Livewire components often add `->latest()` out of habit; on a search query it overrides the ranking you are paying for.

### Search is slow

In rough order of likelihood:

1. **A query that finds nothing runs all four passes**, ending in a scan. Turn off `trigram` if that is the common case on a large table.
2. **The index has many small segments** after a bulk import. Run `php artisan scout:fts5-optimize`.
3. **A filter on an undeclared column** forces a join on every search. Move it into `searchableFilters()` and rebuild.
4. **String-keyed models scan on every write.** See [models with string keys](filtering-and-ordering.md#models-with-string-keys).

### Indexing is slow during an import

Import into a fresh index rather than over an existing one, so each row has nothing to delete first:

```bash
php artisan scout:fts5-rebuild "App\Models\Customer"
```

[More on bulk imports →](recipes.md#bulk-imports)

## Working out what happened

`raw()` returns the driver's own result object, which knows more than the model collection does:

```php
$result = Customer::search($term)->raw();

$result->pass();   // which pass answered: prefix, typo, any, trigram, or null
$result->total();  // how many documents matched, ignoring pagination
$result->ids();    // the keys, in the order the ranking put them
```

To see the SQL the driver actually runs:

```php
DB::listen(fn ($query) => logger($query->sql, $query->bindings));
```
