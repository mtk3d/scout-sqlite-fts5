# Scout SQLite FTS5

[![Tests](https://github.com/mtk3d/scout-sqlite-fts5/actions/workflows/tests.yml/badge.svg)](https://github.com/mtk3d/scout-sqlite-fts5/actions/workflows/tests.yml)
[![Latest version](https://img.shields.io/packagist/v/mtk3d/scout-sqlite-fts5.svg)](https://packagist.org/packages/mtk3d/scout-sqlite-fts5)
[![License](https://img.shields.io/packagist/l/mtk3d/scout-sqlite-fts5.svg)](LICENSE.md)

A [Laravel Scout](https://laravel.com/docs/scout) driver that keeps your search index in SQLite's own full-text engine — [FTS5](https://www.sqlite.org/fts5.html) — right next to your data.

No daemon, no API key, no second datastore to keep in sync. `composer require`, one artisan command, and `Model::search()` works.

```php
Customer::search('kowalsky')->paginate(20);
```

That query finds *Jan Kowalski* despite the typo, because search runs as a cascade of progressively fuzzier passes and stops at the first one that matches. Results come back ranked by BM25, ordered and paginated in SQL.

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
composer require mtk3d/scout-sqlite-fts5
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

## Usage

Everything is plain Scout. Make a model searchable and search it:

```php
class Customer extends Model
{
    use Searchable;

    public function toSearchableArray(): array
    {
        return ['name' => $this->name, 'city' => $this->city];
    }
}
```

```php
Customer::search('kowalski')->get();
Customer::search('kowalski')->where('status', 'active')->latest()->paginate(20);
Customer::search('kowalski')->take(5)->keys();
```

Sorting, filtering and pagination all happen in SQL before results are sliced into pages, so page 2 really is the second page of the order you asked for.

## How search works

A query is answered by four passes, tried strictest first. The first pass that matches anything wins, and the rest never run — so an exact query costs exactly one indexed `MATCH`, and only a query that finds nothing pays for the fuzzy interpretations.

| Pass | For the query `jan kowalsky` | Catches |
|---|---|---|
| `prefix` | `"jan"* AND "kowalsky"*` | exact and partial words |
| `typo` | `"jan"* AND "kowals"*` | a wrong word ending |
| `any` | `"jan"* OR "kowalsky"*` | one word of several being wrong |
| `trigram` | `content LIKE '%owa%' …` | a typo in the middle of a word |

You can ask which pass answered, which is handy for telling a user you guessed:

```php
$result = Customer::search($term)->raw();

$result->pass();  // 'prefix' | 'typo' | 'any' | 'trigram' | null
$result->total(); // matches, ignoring pagination
$result->ids();   // document keys, best match first
```

[Read more about the cascade and how it is ranked →](docs/how-it-works.md)

## Filtering

Filters on columns you declare are answered by the index itself:

```php
public function searchableFilters(): array
{
    return ['tenant_id' => $this->tenant_id];
}
```

```php
Customer::search('kowalski')->where('tenant_id', 1)->get();
```

Filters on columns you did *not* declare still work — the driver joins the model's own table, which is in the same SQLite file. Nothing is silently ignored, and a filter on a column that exists nowhere throws rather than quietly returning wrong results.

[Read more about filtering, ordering and soft deletes →](docs/filtering-and-ordering.md)

## Commands

| Command | What it does |
|---|---|
| `scout:fts5-create` | Creates missing index tables. `--fresh` drops and recreates them. |
| `scout:fts5-rebuild` | Drops, recreates and reimports. Use after changing filters or the tokenizer. |
| `scout:fts5-drop` | Drops index tables. |
| `scout:fts5-optimize` | Merges index segments. Worth running after a bulk import. |

All of them take model class names, and discover models from `scout-fts5.model_paths` when given none. Scout's own `scout:import`, `scout:flush` and `scout:index` work as usual.

## Configuration

The defaults are meant to be usable as they are. The knobs worth knowing about:

```php
'tokenizer' => 'unicode61 remove_diacritics 2', // add `porter` for English stemming
'passes' => ['prefix' => true, 'typo' => true, 'any' => true, 'trigram' => true],
'trigram' => ['size' => 3, 'max_grams' => 24, 'min_ratio' => 0.4],
```

Turning off `any` keeps multi-word queries strict; turning off `trigram` means search never scans. Changing the tokenizer changes the index, so rebuild after touching it.

[Full configuration reference →](docs/configuration.md)

## Documentation

- [How it works](docs/how-it-works.md) — the search cascade, ranking, and what the index actually looks like
- [Filtering and ordering](docs/filtering-and-ordering.md) — filters, sorting, pagination, soft deletes, string keys
- [Configuration](docs/configuration.md) — every option, what it costs, and when it needs a rebuild
- [Recipes](docs/recipes.md) — search boxes, "did you mean", multi-tenancy, NativePHP, bulk imports
- [Troubleshooting](docs/troubleshooting.md) — every error this package throws, and what to do about it

## Things to know

- **SQLite only.** The driver refuses to boot on any other connection rather than failing halfway through a query.
- **The index must share a connection with your models**, since filters and ordering join their table.
- **The `trigram` pass scans.** It is last for that reason, and only runs when everything else came back empty.
- **FTS5 tables cannot be altered.** Adding a filter column means a rebuild; the driver detects the mismatch and says so instead of failing on an insert.

## Testing

```bash
composer test
```

## Credits

Extracted from the search implementation in Wrenchr, a workshop management app, where it replaced a Scout driver that scanned.

## License

MIT. See [LICENSE.md](LICENSE.md).
