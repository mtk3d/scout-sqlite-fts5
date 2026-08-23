# Laravel Scout SQLite FTS5 Driver

[![Tests](https://github.com/mtk3d/scout-sqlite-fts5/actions/workflows/tests.yml/badge.svg)](https://github.com/mtk3d/scout-sqlite-fts5/actions/workflows/tests.yml)
[![Documentation](https://github.com/mtk3d/scout-sqlite-fts5/actions/workflows/docs.yml/badge.svg)](https://mtk3d.github.io/scout-sqlite-fts5/)
[![PHP](https://img.shields.io/badge/php-8.3%20%7C%208.4-777bb4.svg)](https://www.php.net/supported-versions.php)
[![Latest version](https://img.shields.io/packagist/v/mtk3d/scout-sqlite-fts5.svg)](https://packagist.org/packages/mtk3d/scout-sqlite-fts5)
[![License](https://img.shields.io/github/license/mtk3d/scout-sqlite-fts5.svg)](LICENSE.md)

Full-text search for your Eloquent models, powered by SQLite's own [FTS5](https://www.sqlite.org/fts5.html) engine. A [Laravel Scout](https://laravel.com/docs/scout) driver that keeps the search index right next to your data.

No daemon, no API key, no second datastore to keep in sync. `composer require`, one artisan command, and `Model::search()` works.

```php
Customer::search('kowalsky')->paginate(20);
```

That query finds *Jan Kowalski* despite the typo, because search runs as a cascade of progressively fuzzier passes and stops at the first one that matches. Results come back ranked by BM25, ordered and paginated in SQL.

## Why this driver

Scout's built-in `database` driver searches your model's own columns — with `LIKE`, or through a full-text index you create yourself.

Dedicated search engines — Meilisearch, Typesense, Algolia — mean running a service alongside your app. For a desktop app, a NativePHP build, a single-tenant SaaS, or a CLI tool, that is a lot of moving parts for a search box.

SQLite already ships a real inverted index. This package points Scout at it.

Not on SQLite? [`namoshek/laravel-scout-database`](https://github.com/Namoshek/laravel-scout-database) is a Scout driver that builds its own inverted index in ordinary tables, and runs on every database Laravel supports.

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

The full documentation is published at **[mtk3d.github.io/scout-sqlite-fts5](https://mtk3d.github.io/scout-sqlite-fts5/)**, built with [mdBook](https://rust-lang.github.io/mdBook/) from the same pages that live in [`docs/`](docs) in this repository.

- [How it works](docs/how-it-works.md) — the search cascade, ranking, and what the index actually looks like
- [Architecture](docs/architecture.md) — C4 diagrams of where this sits and what it is made of
- [Filtering and ordering](docs/filtering-and-ordering.md) — filters, sorting, pagination, soft deletes, string keys
- [Configuration](docs/configuration.md) — every option, what it costs, and when it needs a rebuild
- [Recipes](docs/recipes.md) — search boxes, "did you mean", multi-tenancy, NativePHP, bulk imports
- [Edge cases](docs/edge-cases.md) — CJK, zeroes, short queries, empty filters, and other boundaries
- [Decisions](docs/decisions/index.md) — why the package works this way, and what each choice costs
- [Troubleshooting](docs/troubleshooting.md) — every error this package throws, and what to do about it

## Things to know

- **Simplified search, not linguistic analysis.** FTS5 is the engine; this package chooses what to index and which queries to try. There is no stemmer — matching is over strings, made forgiving by the cascade. [What that costs and what covers for it →](docs/how-it-works.md#no-stemmer)
- **SQLite only.** The driver refuses to boot on any other connection rather than failing halfway through a query.
- **The index lives in your existing database.** The tables are created on the connection Laravel is already configured with, alongside your own — there is no second database to provision. ([It can be given a file of its own](docs/configuration.md#keeping-the-index-in-its-own-file), at the cost of sorting and unindexed filters.)
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
