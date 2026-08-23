# How it works

[← back to the README](https://github.com/mtk3d/scout-sqlite-fts5#readme)

## The index

Every searchable model gets one FTS5 virtual table, named after its `searchableAs()` value plus the configured suffix — `customers` becomes `customers_fts`.

```sql
CREATE VIRTUAL TABLE "customers_fts" USING fts5(
    "content",
    "tenant_id" UNINDEXED,
    tokenize='unicode61 remove_diacritics 2'
)
```

Everything `toSearchableArray()` returns is flattened, joined with spaces, normalized and stored in the single `content` column. FTS5 tokenizes that column and maintains the inverted index; the driver never has to.

Values from `searchableFilters()` become `UNINDEXED` columns: stored and filterable, but not searched.

### Where the key goes

Models with an integer key store it in the table's implicit `rowid`. That makes an update or delete an index lookup:

```sql
DELETE FROM "customers_fts" WHERE rowid IN (7, 9)
```

Models with a string key — UUID, ULID — get an explicit `doc_id UNINDEXED` column instead, because a `rowid` has to be an integer. FTS5 has nowhere to put a secondary index, so deleting by `doc_id` is a table scan. It works fine at small and medium scale; it is worth knowing before you bulk-import a million rows.

Scout's usual write path applies: models are indexed on save, removed on delete, and `scout.queue` moves that work to a queue if you want it out of the request.

## The search cascade

A query is answered by four passes, tried strictest first. **The first pass that matches anything wins and the rest never execute.** An exact query costs one indexed `MATCH`; only a query that finds nothing pays for the fuzzier interpretations below it.

Take the query `jan kowalsky`, against a document containing *Jan Kowalski*.

### 1. `prefix` — every word, as a prefix

```
"jan"* AND "kowalsky"*
```

Matches exact words and partial ones, so search works as the user types. Here it fails: nothing starts with *kowalsky*.

### 2. `typo` — every word, shortened

```
"jan"* AND "kowals"*
```

Each word loses its last `typo.trim` characters (two by default), never dropping below `typo.min_prefix`. This is the cheapest useful typo tolerance there is: most misspellings are in the ending, and a shortened prefix still uses the index. Here it matches.

Words too short to shorten come back unchanged — the driver notices that the shortened query is identical to the prefix query and skips the pass rather than running it twice.

### 3. `any` — one word is enough

```
"jan"* OR "kowalsky"*
```

For when someone got one word of several wrong, or typed a word that simply is not in the document. Skipped for single-word queries, where it would be identical to the prefix pass.

### 4. `trigram` — substrings, anywhere

```sql
content LIKE '%kow%' OR content LIKE '%owe%' OR …
```

The pass that catches what a prefix query structurally cannot. `kowerlski` shares no usable prefix with `kowalski` — the typo is in the middle — but it shares most of its three-character substrings.

This is a scan. It is last for that reason, and it only runs when the three indexed passes all came back empty.

#### Why there is a threshold

A naive substring fallback matches far too much. The query *zupelnie inne slowa* has nothing to do with *Jan Kowalski*, but `kowalski` contains `owa`, so a single shared trigram is enough to "match". Ask enough three-letter questions and everything answers one of them.

So a document has to contain a **share of a single word's substrings** — `trigram.min_ratio`, 40% by default — not just one of any word's:

| Query | vs *Jan Kowalski* | Result |
|---|---|---|
| `kowerlski` | 3 of 7 substrings of one word (43%) | matches |
| `zupelnie inne slowa` | best word: 1 of 3 (33%) | no match |

Measuring per word rather than across the whole query is what makes those two separable. A misspelled word keeps most of its own substrings; an unrelated query only ever shares a stray one or two of any single word's.

Raise the ratio if nonsense still comes back, lower it to catch heavier misspellings.

## Ranking

Within an indexed pass, documents are ordered by FTS5's [BM25](https://www.sqlite.org/fts5.html#the_bm25_function) score: a term is worth more in a short document than in a long one, and rarer terms count for more. A customer whose name *is* "Kowalski" outranks one who has "Kowalski" buried in a paragraph of notes.

The substring pass has no BM25 score to speak of, so its documents are ranked by how many of the query's substrings they contain.

The order is decided in SQL and carried through hydration: `search()->get()` returns models in relevance order, not in whatever order the database felt like returning rows.

## No stemmer

FTS5 is the search engine here. It owns the index, the tokenizing and the ranking; this package decides what to put in, what to ask, and in what order to ask it. That division is worth keeping in mind, because it explains what is missing as much as what is there.

What is missing is stemming — reducing inflected forms to a shared root, so that a search for one form finds the others:

```
biegał, biegnie, biegami  →  bieg
engineering, engineer     →  engin
```

Nothing in this package does that. Words go into the index as the tokenizer split them and come out the same way. Search here is string matching over an inverted index, made forgiving by trying several shapes of the same query — not linguistic analysis.

That is a deliberate limit rather than an oversight. A stemmer is per-language, has to be shipped and maintained for each one, and has to agree exactly between indexing and searching or the two stop meeting. Drivers that build their own index carry a dozen of them for this reason.

### What you get instead

FTS5 ships one stemmer, for English, and you can turn it on:

```php
'tokenizer' => 'porter unicode61 remove_diacritics 2',
```

For everything else, the `typo` pass turns out to approximate suffix stripping by accident. It shortens each word and matches the remainder as a prefix — and inflection mostly changes endings, so the two land in a similar place. Against an index containing *Wymiana rozrządu*:

| Query | Found by |
|---|---|
| `wymiana` | prefix |
| `wymiany` | typo |
| `wymianę` | typo |
| `wymianie` | typo |
| `rozrządu` | prefix |
| `rozrządem` | typo |

All six reach the document, with no stemmer and no configuration. Polish is not among the languages Snowball stems, so for a language like this the accident is worth more than the real thing would be.

Do not mistake it for one, though. Shortening is blind where a stemmer knows roots, so words that merely look alike are reached too: searching `kowalski` finds *Kowalczyk*, because they share three of six substrings and that clears the threshold. Both behaviours are pinned in [the edge cases](edge-cases.md).

## Normalization

Text is normalized on the way into the index and on the way into a query, so the two always agree.

The default normalizer lowercases and folds Latin diacritics: Polish, German, Nordic, Czech, Hungarian, Romanian and Romance alphabets all collapse to ASCII. `Kraków` is stored as `krakow`, and a search for `krakow`, `Kraków` or `KRAKÓW` all reduce to the same thing.

FTS5's `unicode61 remove_diacritics 2` tokenizer already folds diacritics inside the index, so why fold again in PHP? Because the substring pass uses `LIKE`, which never goes through the tokenizer. Without the PHP-side fold, `krakow` would find *Kraków* in the indexed passes and lose it in the fallback.

[Swapping the normalizer →](configuration.md#normalization)

## Query safety

Words are handed to FTS5 as quoted phrases:

```
"jan"* AND "kowalsky"*
```

FTS5 parses the contents of a `MATCH` as a query language of its own, so a user typing `AND`, `NEAR`, `-` or `(` would otherwise be writing queries rather than searching for words. Quoting each word means `kowalski OR nowak` searches for the literal word *or*, and a stray `"` is a character rather than a syntax error.

The match expression itself is bound as a statement parameter, as is every filter value.
