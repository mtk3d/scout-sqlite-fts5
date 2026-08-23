# Laravel Scout SQLite FTS5 Driver

Full-text search for your Eloquent models, powered by SQLite's own [FTS5](https://www.sqlite.org/fts5.html) engine. A [Laravel Scout](https://laravel.com/docs/scout) driver that keeps the search index right next to your data.

No daemon, no API key, no second datastore to keep in sync.

```php
Customer::search('kowalsky')->paginate(20);
```

That query finds *Jan Kowalski* despite the typo, because search runs as a cascade of progressively fuzzier passes and stops at the first one that matches. Results come back ranked by BM25, ordered and paginated in SQL.

## Install

```bash
composer require mtk3d/scout-sqlite-fts5
```

```dotenv
SCOUT_DRIVER=sqlite-fts5
```

```bash
php artisan scout:fts5-create
php artisan scout:import "App\Models\Customer"
```

There is no migration to publish. Index tables are derived from the models that use `Laravel\Scout\Searchable`, and `scout:fts5-rebuild` recreates them from scratch whenever you need.

## Where to go next

**[How it works](how-it-works.md)** is the one to read if you want to know what you are running: what the index looks like, what each of the four passes does, why the substring pass has a threshold, and how results are ranked.

**[Filtering and ordering](filtering-and-ordering.md)** covers `where()`, `whereIn()`, sorting, what pagination guarantees, soft deletes, and how models with string keys differ.

**[Configuration](configuration.md)** is the reference: every option, what it costs, whether it needs a rebuild, and how to swap the normalizer or any other service.

**[Recipes](recipes.md)** has the patterns — a search box, telling a user the match was fuzzy, indexing relations, multi-tenancy, bulk imports, NativePHP, and testing search in your own application.

**[Troubleshooting](troubleshooting.md)** lists every error the package throws with what to do about it, plus the behaviour that looks like a bug and is not.

## Requirements

- PHP 8.3+
- Laravel 12 or 13, with Scout 11
- SQLite compiled with FTS5 — the default in every mainstream PHP build since 3.9

```bash
php -r 'echo (new PDO("sqlite::memory:"))->exec("CREATE VIRTUAL TABLE t USING fts5(c)") === false ? "missing" : "ok";'
```

The package is [MIT licensed](https://github.com/mtk3d/scout-sqlite-fts5/blob/main/LICENSE.md) and lives [on GitHub](https://github.com/mtk3d/scout-sqlite-fts5).
