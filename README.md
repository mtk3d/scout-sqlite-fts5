# Scout SQLite FTS5

A [Laravel Scout](https://laravel.com/docs/scout) driver that keeps your search index in SQLite's own full-text engine — [FTS5](https://www.sqlite.org/fts5.html) — right next to your data.

No daemon, no API key, no second datastore to keep in sync. `composer require`, one artisan command, and `Model::search()` works.

```php
Customer::search('kowalsky')->paginate(20);
```

That query finds *Jan Kowalski*, even though it is misspelled, because search runs as a cascade of progressively fuzzier passes and stops at the first one that matches. Results come back ranked by BM25, ordered and paginated in SQL.

## Why this driver

Scout's built-in `database` driver runs `LIKE '%term%'` across your columns. It works, and it stops working somewhere around a few thousand rows.

The alternatives that scale mean running something: Meilisearch, Typesense, Algolia. For a desktop app, a NativePHP build, a single-tenant SaaS, or a CLI tool, that is a lot of moving parts for a search box.

SQLite already ships a real inverted index. This package points Scout at it.

| | this package | `database` driver | Meilisearch / Typesense |
|---|---|---|---|
| Infrastructure | none | none | a service to run |
| Index | FTS5 inverted index | none, scans on read | own datastore |
| Ranking | BM25 | none | own |
| Typo tolerance | four-pass cascade | none | built in |
| Databases | SQLite only | all | any |

If you need this on MySQL or Postgres, use [`namoshek/laravel-scout-database`](https://github.com/Namoshek/laravel-scout-database) instead — it builds a portable inverted index in ordinary tables, at the cost of a larger index and slower writes.

## Requirements

- PHP 8.3+
- Laravel 12 or 13, with Scout 11
- SQLite compiled with FTS5 — the default in every mainstream PHP build since 3.9

Check yours:

```bash
php -r 'echo (new PDO("sqlite::memory:"))->exec("CREATE VIRTUAL TABLE t USING fts5(c)") === false ? "missing" : "ok";'
```

## Installation

```bash
composer require wrenchr/scout-sqlite-fts5
```

Point Scout at the driver in `.env`:

```dotenv
SCOUT_DRIVER=sqlite-fts5
```

Then create the index tables and fill them:

```bash
php artisan scout:fts5-create
php artisan scout:import "App\Models\Customer"
```

`scout:fts5-create` finds every model in `app/Models` that uses `Laravel\Scout\Searchable` and gives each one a virtual table named after `searchableAs()` — `customers` becomes `customers_fts`. There is no migration to publish and nothing to commit: the tables are derived from your models, and `scout:fts5-rebuild` recreates them from scratch whenever you need.

Publishing the config is optional:

```bash
php artisan vendor:publish --tag=scout-fts5-config
```

## How search works

A query is answered by four passes, tried strictest first. The first pass that matches anything wins, and the rest never run — so an exact query costs exactly one indexed `MATCH`, and only a query that finds nothing pays for the fuzzy interpretations.

| Pass | For the query `jan kowalsky` | Catches |
|---|---|---|
| `prefix` | `"jan"* AND "kowalsky"*` | exact and partial words |
| `typo` | `"jan"* AND "kowals"*` | a wrong word ending |
| `any` | `"jan"* OR "kowalsky"*` | one word of several being wrong |
| `trigram` | `content LIKE '%owa%' …` | a typo in the middle of a word |

The first three use the FTS5 index. The last one is a scan and exists to catch what a prefix query structurally cannot: `kowerlski` shares no usable prefix with `kowalski`, but it shares most of its three-character substrings.

To keep that last pass from answering every query with noise, a document has to contain a *share* of a single word's substrings — 40% by default — not just one of them. Nonsense finds nothing; a misspelled name still finds its owner.

You can see which pass answered a query, which is handy for telling a user you guessed:

```php
$result = Customer::search($term)->raw();

$result->pass();  // 'prefix' | 'typo' | 'any' | 'trigram' | null
$result->total(); // matches, ignoring pagination
$result->ids();   // document keys, best match first
```

Turn passes off to make search stricter and faster:

```php
'passes' => [
    'prefix' => true,
    'typo' => true,
    'any' => false,     // never widen a multi-word query
    'trigram' => false, // never scan
],
```

## Ranking

Within a pass, documents are ordered by FTS5's BM25 score: a term is worth more in a short document than in a long one, and rarer terms count for more. Substring matches, which have no BM25 score, are ranked by how many of the query's substrings they contain.

The order survives hydration — `search()->get()` returns models in relevance order, not in whatever order the database felt like returning them.

## Filtering

Filters on columns that live in the index are answered by the index. Declare them on the model:

```php
class Customer extends Model
{
    use Searchable;

    public function toSearchableArray(): array
    {
        return ['name' => $this->name, 'city' => $this->city];
    }

    public function searchableFilters(): array
    {
        return ['tenant_id' => $this->tenant_id];
    }
}
```

```php
Customer::search('kowalski')->where('tenant_id', 1)->get();
```

Each key becomes an `UNINDEXED` column on the virtual table, so it is stored and filterable but not searched. The method is also called on a bare model instance to work out the table's columns, so return the same keys regardless of the model's state.

Adding a filter means rebuilding: FTS5 tables have no `ALTER TABLE`.

```bash
php artisan scout:fts5-rebuild "App\Models\Customer"
```

Filters on columns you did *not* declare still work. Because the index sits in the same SQLite file as your data, the driver joins the model's own table:

```php
Customer::search('kowalski')->where('status', 'active')->get();
```

Declared columns are faster — no join — but nothing is silently ignored, and a filter on a column that exists nowhere throws instead of quietly returning wrong results. `whereIn()` and `whereNotIn()` work the same way.

## Ordering and pagination

Both happen in SQL, before results are sliced into pages:

```php
Customer::search('kowalski')->latest()->paginate(20);
```

This matters more than it sounds. An engine that pages first and sorts afterwards sorts one arbitrary slice per page, so records appear twice, or never — the pages do not add up to the result set. Here the ordering reaches the index query, so page 2 is the second page of the order you asked for.

## Soft deletes

Set `scout.soft_delete` to `true` and models using `SoftDeletes` get a `__soft_deleted` column in their index. Trashed records stay indexed but drop out of results, and `withTrashed()` and `onlyTrashed()` bring them back.

## Models with string keys

Integer-keyed models store their key in the index table's implicit `rowid`, which makes writes an index lookup. Models with a UUID or ULID key get an explicit `doc_id` column instead, and pay a table scan per write — FTS5 has nowhere to put a secondary index. It works, and it is worth knowing about before you bulk-import a million rows.

## Commands

| Command | What it does |
|---|---|
| `scout:fts5-create` | Creates missing index tables. `--fresh` drops and recreates them. |
| `scout:fts5-rebuild` | Drops, recreates and reimports. Use after changing filters or the tokenizer. |
| `scout:fts5-drop` | Drops index tables. |
| `scout:fts5-optimize` | Merges index segments. Worth running after a bulk import. |

All of them take model class names, and discover models from `scout-fts5.model_paths` when given none. Scout's own `scout:import`, `scout:flush` and `scout:index` work as usual.

## Configuration

```php
'connection' => env('SCOUT_FTS5_CONNECTION'), // null = default connection
'suffix' => '_fts',                           // customers -> customers_fts
'tokenizer' => 'unicode61 remove_diacritics 2',
'auto_create' => true,                        // create missing tables on write
'normalizer' => DiacriticsNormalizer::class,
'passes' => ['prefix' => true, 'typo' => true, 'any' => true, 'trigram' => true],
'typo' => ['trim' => 2, 'min_prefix' => 3],
'trigram' => ['size' => 3, 'max_grams' => 24, 'min_ratio' => 0.4],
'model_paths' => [app_path('Models')],
```

Changing the tokenizer changes the index, so rebuild after touching it. Adding `porter` to the tokenizer gives you English stemming — `'porter unicode61 remove_diacritics 2'` — so *engineering* matches *engineer*.

### Normalization

Text is normalized on the way in and on the way out, so both sides of the index always agree. The default lowercases and folds Latin diacritics: Polish, German, Nordic, Czech, Hungarian, Romanian and Romance alphabets all collapse to ASCII, which is what lets `krakow` find *Kraków*.

Swap it for your own by binding the contract:

```php
$this->app->bind(\Wrenchr\Scout\Fts5\Contracts\Normalizer::class, MyNormalizer::class);
```

Every other service — the indexer, the seeker, the schema — is resolved from the container too, so any of them can be replaced.

## Things to know

- **SQLite only.** The driver refuses to boot on any other connection rather than failing halfway through a query.
- **The index must share a connection with your models**, since filters and ordering join their table.
- **The `trigram` pass scans.** It is last for that reason, and it only runs when everything else came back empty. Turn it off if your tables are large and you would rather return nothing than scan.
- **FTS5 tables cannot be altered.** Adding a filter column means a rebuild; the driver detects the mismatch and tells you so instead of failing on an insert.

## Testing

```bash
composer test
```

## Credits

Extracted from the search implementation in [Wrenchr](https://github.com/wrenchr), where it replaced a Scout driver that scanned.

## License

MIT. See [LICENSE.md](LICENSE.md).
