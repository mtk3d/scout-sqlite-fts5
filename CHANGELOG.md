# Changelog

All notable changes to `mtk3d/scout-sqlite-fts5` are documented here.

## v0.1.0 - unreleased

Initial release.

- Scout engine backed by SQLite FTS5 virtual tables, one per searchable model.
- Four-pass search cascade: prefix, shortened prefix, any word, substring.
- BM25 ranking, preserved through model hydration.
- Ordering, filtering and pagination pushed into SQL.
- Filters on indexed columns via `searchableFilters()`, and on model columns via a join.
- Soft delete support.
- Integer keys stored in `rowid`; string keys in a `doc_id` column.
- `scout:fts5-create`, `scout:fts5-rebuild`, `scout:fts5-drop` and `scout:fts5-optimize`.
