# 1. Build on SQLite FTS5 rather than a portable index

**Status:** accepted

## Context

A Scout driver that stores its index in the application's own database can go two ways.

It can build an inverted index by hand, in ordinary tables — one row per term per document — and run on every database Laravel supports. This is what [`namoshek/laravel-scout-database`](https://github.com/Namoshek/laravel-scout-database) does, and it is the right answer if your models live in MySQL or Postgres.

Or it can use a full-text engine the database already ships. SQLite has one: FTS5, a real inverted index with BM25 ranking, prefix queries and its own tokenizers, maintained by the database rather than by us.

## Decision

Use FTS5, and accept that the package works only on SQLite.

## Consequences

The index is maintained by SQLite. There is no term table to keep consistent, no scoring to implement in SQL, and no query planner to fight — `MATCH` and `bm25()` are the engine's own.

Ranking comes for free and is better than anything worth hand-rolling: BM25 weighs term rarity and document length, which a naive implementation does not.

The package refuses to boot on a connection that is not SQLite, rather than failing halfway through a `CREATE VIRTUAL TABLE`. Anyone on another database is pointed at the portable alternative in the error message and the README.

Applications that ship a database file — desktop builds, NativePHP, single-tenant deployments, CLI tools — get full-text search with no service to run, which is the audience this package is for.
