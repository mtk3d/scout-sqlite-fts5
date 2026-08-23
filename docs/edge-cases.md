# Edge cases

[← back to the README](https://github.com/mtk3d/scout-sqlite-fts5#readme)

What happens at the boundaries. Everything on this page is pinned by a test in [`tests/EdgeCaseTest.php`](https://github.com/mtk3d/scout-sqlite-fts5/blob/main/tests/EdgeCaseTest.php), so the page cannot drift from the behaviour — if you find a case that contradicts what is written here, that is a bug in the package rather than in the documentation.

## Text and languages

### `0` is indexed; empty strings are not

`toSearchableArray()` values are dropped when they are `null` or `''`, and kept otherwise. A zero survives:

```php
Customer::create(['name' => '0', 'city' => 'Zero', 'notes' => '']);
// indexed content: "0 zero"
```

This matters more than it sounds. `array_filter()` without a callback — the obvious way to drop empty values — also drops `"0"`, `0` and `false`. House numbers, meter readings, account balances and status codes all vanish from the index that way.

### CJK matches from the start of a run, not the middle

The `unicode61` tokenizer splits on characters that are neither letters nor digits. A run of CJK contains none, so it becomes a single token:

```php
Customer::create(['name' => '東京都渋谷区']);

Customer::search('東京')->get();  // 1 — a prefix of the token
Customer::search('渋谷')->get();  // 0 — the middle of it
```

The substring pass would normally catch the second case, but `渋谷` is two characters and the pass skips words shorter than `trigram.size`. Lowering the size brings it back:

```php
'trigram' => ['size' => 2],
```

With that, `渋谷` matches through the substring pass. The cost is a broader and slower last pass for every language, so set it only if you index CJK.

The `trigram` tokenizer is the other option and behaves differently from what its name suggests here: it indexes substrings, so mid-word matching works for Latin text, but SQLite requires queries of at least three characters — which puts two-character CJK words out of reach entirely.

### Alphabets with no ASCII equivalent work normally

Cyrillic, Greek and the rest tokenize like Latin, and prefix matching works:

```php
Customer::create(['name' => 'Ковальский Ян']);

Customer::search('ковальский')->get();  // 1
Customer::search('коваль')->get();      // 1
```

The default normalizer folds Latin diacritics and lowercases everything; it leaves other scripts alone, and `unicode61 remove_diacritics 2` handles their case folding inside the index.

### Punctuation splits words, which is usually what you want

A phone number is indexed as written, and found either way it is typed:

```php
Customer::create(['name' => 'Nowak', 'notes' => '+48 601-234-567']);

Customer::search('601-234')->raw()->pass();     // 'prefix'
Customer::search('601234567')->raw()->pass();   // 'trigram'
```

Typed with separators, the digit groups are words and the strict pass answers. Typed as one run, no token matches — and the substring pass recognises `601`, `234` and `567` inside the content, which is enough to clear the threshold.

## Queries

### A short query that matches nothing finds nothing

```php
Customer::create(['name' => 'Kowalski']);

Customer::search('ko')->raw()->pass();   // 'prefix' — still prefixes a real token
Customer::search('xy')->raw()->pass();   // null
```

Words shorter than `trigram.size` have no substrings to fall back on, so the cascade runs out instead of matching everything. A two-character query is either a real prefix or nothing.

### A query with no words returns nothing

`'   ...   '` and `''` produce no tokens, so no search runs at all. The result is empty with a total of zero — not an error, and not everything.

### FTS5 syntax is searched for, not executed

See [decision 7](decisions/0007-quote-every-word-as-a-phrase.md). `kowalski OR nowak` looks for the literal word *or*, which is why it falls through to the `any` pass rather than being answered by the strict one.

## Pagination and limits

### A page past the last one reports the real total

```php
$page = Customer::search('kowalski')->paginate(10, 'page', 99);

$page->items();  // []
$page->total();  // 1
```

### `take()` is overridden by a page size

```php
Customer::search('kowalski')->take(1)->get();          // 1
Customer::search('kowalski')->take(1)->paginate(10);   // 3
```

`take()` sets the size of an unpaginated search. Once a page size is given it wins — the same way Scout's other engines behave.

### An empty `whereIn` matches nothing

```php
Customer::search('kowalski')->whereIn('tenant_id', [])->get();  // 0
```

This is SQL's semantics rather than a decision of this package, and it is the safe direction: an empty allow-list allows nothing. Guard the call site if you meant "no filter".

## The index itself

### Searching a model with no index table returns nothing

It does not throw. A missing table means an empty result, so a dropped or not-yet-created index degrades to "no results" rather than breaking every page in the application. Indexing into a missing table *does* throw when `auto_create` is off — see [decision 8](decisions/0008-fail-loudly-on-unknown-filters.md) for why the two directions differ.

### An index whose columns no longer match its model throws

Adding a key to `searchableFilters()`, or turning on `scout.soft_delete`, changes the table's shape. FTS5 has no `ALTER TABLE`, so the driver detects the mismatch on the next write and names the rebuild command rather than letting the insert fail on `no such column`.

### A model that stops being searchable is removed

An empty `toSearchableArray()` is Scout's way of saying "keep this out of results". Anything already indexed for that model is deleted on its next save, so the record disappears from search without being deleted from the database.

### Virtual tables do not survive a transaction rollback

`RefreshDatabase` wraps each test in a transaction, and `CREATE VIRTUAL TABLE` inside a transaction is rolled back with it. With `auto_create` on — the default — the table is simply recreated on the next write, so tests pass either way. With it off, create the tables in your test setup.

### Table prefixes are applied to the index too

A connection with a `prefix` gets `app_customers_fts`, and every hand-written fragment — `CREATE`, `MATCH`, `bm25()`, `PRAGMA` — goes through the same naming as the query builder. This is exercised end to end in [`tests/TablePrefixTest.php`](https://github.com/mtk3d/scout-sqlite-fts5/blob/main/tests/TablePrefixTest.php), because a mismatch here only appears on connections that set a prefix, which is precisely when it is hardest to debug.
