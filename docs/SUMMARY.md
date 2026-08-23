# Summary

[Introduction](index.md)

---

- [How it works](how-it-works.md)
- [Filtering and ordering](filtering-and-ordering.md)
- [Configuration](configuration.md)
- [Recipes](recipes.md)
- [Edge cases](edge-cases.md)
- [Troubleshooting](troubleshooting.md)

---

- [Architecture](architecture.md)
- [Decisions](decisions/index.md)
  - [1. SQLite FTS5 over a portable index](decisions/0001-sqlite-fts5-over-a-portable-index.md)
  - [2. Tables derived from models](decisions/0002-tables-derived-from-models.md)
  - [3. Integer keys in rowid](decisions/0003-integer-keys-in-rowid.md)
  - [4. A cascade of passes](decisions/0004-a-cascade-of-passes.md)
  - [5. A threshold for substring matching](decisions/0005-a-threshold-for-substring-matching.md)
  - [6. Ordering and pagination in SQL](decisions/0006-ordering-and-pagination-in-sql.md)
  - [7. Quote every word as a phrase](decisions/0007-quote-every-word-as-a-phrase.md)
  - [8. Fail loudly on unknown filters](decisions/0008-fail-loudly-on-unknown-filters.md)
  - [9. Testbench for tests](decisions/0009-testbench-for-tests.md)
