# Configuration

[← back to the README](https://github.com/mtk3d/scout-sqlite-fts5#readme)

```bash
php artisan vendor:publish --tag=scout-fts5-config
```

Publishing is optional — the packaged defaults apply either way. Every option below lives in `config/scout-fts5.php`.

Options marked **rebuild** change the shape or contents of the index, so run `scout:fts5-rebuild` after touching them.

## `connection`

```php
'connection' => env('SCOUT_FTS5_CONNECTION'), // null = the default connection
```

The SQLite connection the index tables live on. `null` uses the application's default, which puts the index in the same file as your data — the usual arrangement, and the one where everything works.

The driver checks the connection's driver at boot and throws if it is not SQLite, rather than failing halfway through a query.

### Keeping the index in its own file

Pointing this at a second SQLite connection puts the whole index in a separate file. That has real appeal: the file can be excluded from backups, deleted and rebuilt without touching application data, and kept out of whatever replicates your database.

Search and filters on indexed columns work exactly as before. What stops working is anything that needs the model's own table, because SQLite cannot join across connections:

```php
Customer::search('kowalski')->get();                       // works
Customer::search('kowalski')->where('tenant_id', 1)->get(); // works — indexed filter
Customer::search('kowalski')->latest()->get();              // throws
Customer::search('kowalski')->where('status', 1)->get();    // throws — not an indexed filter
```

The driver detects the mismatch and says so, rather than letting SQLite report a table it was never going to find.

Take this arrangement if you sort by relevance and declare every column you filter on in `searchableFilters()`. Otherwise leave the index where your data is.

## `suffix` — rebuild

```php
'suffix' => '_fts',
```

Appended to `searchableAs()` to name the index table. `customers` becomes `customers_fts`.

Changing it does not rename anything; it points the driver at differently named tables. Create them first, or the next search will simply find nothing.

## `tokenizer` — rebuild

```php
'tokenizer' => 'unicode61 remove_diacritics 2',
```

Passed verbatim into `CREATE VIRTUAL TABLE … tokenize='…'`. The default folds diacritics and splits on non-alphanumerics.

Useful variations:

| Tokenizer | Effect |
|---|---|
| `unicode61 remove_diacritics 2` | the default |
| `porter unicode61 remove_diacritics 2` | English stemming — *engineering* matches *engineer* |
| `unicode61 remove_diacritics 2 tokenchars '-_'` | keeps hyphens and underscores inside words |
| `trigram` | substring matching in the index itself; requires queries of 3+ characters |

See [SQLite's tokenizer documentation](https://www.sqlite.org/fts5.html#tokenizers) for the full grammar.

## `auto_create`

```php
'auto_create' => true,
```

Creates a missing index table the first time a model is indexed. Convenient in development and in apps that ship a database file with no migration step, such as NativePHP builds.

Set it to `false` to manage the schema explicitly. Indexing a model with no table then throws instead of creating one.

## `normalizer` — rebuild

```php
'normalizer' => DiacriticsNormalizer::class,
```

Applied to both indexed content and incoming queries, so the two always agree. The default lowercases and folds Latin diacritics.

### Normalization

Write your own by implementing the contract:

```php
namespace App\Search;

use ScoutFts5\Contracts\Normalizer;

class StreetNormalizer implements Normalizer
{
    public function normalize(string $text): string
    {
        return str_replace(['strasse', 'straße'], 'str', mb_strtolower($text));
    }
}
```

Point the config at it, or bind the contract if it needs dependencies:

```php
$this->app->bind(Normalizer::class, StreetNormalizer::class);
```

Every other service is resolved from the container too — `ScoutFts5\Indexer`, `ScoutFts5\Seeker`, `ScoutFts5\Support\Schema` and `ScoutFts5\Engine` can all be swapped the same way.

## `passes`

```php
'passes' => [
    'prefix' => true,
    'typo' => true,
    'any' => true,
    'trigram' => true,
],
```

Which of the [four passes](how-it-works.md#the-search-cascade) may run. They are always tried strictest first, and the first one that matches wins.

Turning passes off makes search stricter and faster, since a query that finds nothing stops sooner:

- **`any => false`** keeps multi-word queries strict. Searching *jan kowalski* will not fall back to everyone named Jan.
- **`trigram => false`** means search never scans. Worth it on large tables, or anywhere a query that finds nothing must stay cheap.

Disabling `prefix` is possible but rarely sensible — it is the pass that answers ordinary queries.

## `typo`

```php
'typo' => [
    'trim' => 2,
    'min_prefix' => 3,
],
```

`trim` is how many characters come off the end of each word; `min_prefix` is the shortest prefix the pass will search for.

Raising `trim` catches worse endings at the cost of precision: at `4`, searching *kowalxyzw* finds *Kowalski*, and so does searching *kowalcokolwiek*.

## `trigram`

```php
'trigram' => [
    'size' => 3,
    'max_grams' => 24,
    'min_ratio' => 0.4,
],
```

Tuning for the substring pass. `size` is the substring length, `max_grams` caps how many substrings one query may produce, and `min_ratio` is the share of a single word's substrings a document must contain to count as a match.

`min_ratio` is the one worth tuning. Too low and nonsense queries come back with results; too high and the pass stops catching the misspellings it exists for. See [why there is a threshold](how-it-works.md#why-there-is-a-threshold) for how the numbers actually fall.

## `model_paths` and `models`

```php
'model_paths' => [app_path('Models')],
'models' => [],
```

Where the `scout:fts5-*` commands look for searchable models when you do not name one. Paths are scanned recursively for classes that use `Laravel\Scout\Searchable`.

List classes in `models` to skip scanning entirely — faster, and explicit about what gets an index:

```php
'models' => [
    App\Models\Customer::class,
    App\Models\Invoice::class,
],
```

When `models` is non-empty, `model_paths` is ignored.
