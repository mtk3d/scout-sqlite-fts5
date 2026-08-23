# Decisions

[← back to the README](https://github.com/mtk3d/scout-sqlite-fts5#readme)

Why this package works the way it does. Each record states the problem as it stood, what was chosen, and what that choice costs — the last part being the one worth reading before you file a bug about it.

[Record 0](0000-cheap-simple-indexing-for-scout-on-sqlite.md) is the one the package exists to serve; the rest are choices made underneath it.

| # | Decision | Costs you |
|---|---|---|
| [0](0000-cheap-simple-indexing-for-scout-on-sqlite.md) | **Why this exists:** cheap, simple indexing for Scout on SQLite | Narrow scope by design |
| [1](0001-sqlite-fts5-over-a-portable-index.md) | Build on SQLite FTS5 rather than a portable index | Runs on SQLite only |
| [2](0002-tables-derived-from-models.md) | Derive index tables from models, not migrations | Schema changes need a rebuild |
| [3](0003-integer-keys-in-rowid.md) | Store integer keys in `rowid` | String-keyed models scan on write |
| [4](0004-a-cascade-of-passes.md) | Answer a query with a cascade of passes | A query that finds nothing runs all four |
| [5](0005-a-threshold-for-substring-matching.md) | Require a share of one word's substrings | A threshold to tune, not a universal answer |
| [6](0006-ordering-and-pagination-in-sql.md) | Order, filter and paginate in SQL | The index must share a connection with the models |
| [7](0007-quote-every-word-as-a-phrase.md) | Quote every word as an FTS5 phrase | Users cannot write FTS5 query syntax |
| [8](0008-fail-loudly-on-unknown-filters.md) | Throw on a filter that matches no column | A typo breaks the request instead of quietly narrowing it |
| [9](0009-testbench-for-tests.md) | Test through a booted Laravel application | The full framework is a dev dependency |
