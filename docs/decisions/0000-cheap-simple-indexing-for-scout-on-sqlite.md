# 0. Cheap, simple indexing for Scout on SQLite

**Status:** accepted

This is the decision the package exists to serve. Every record that follows is a choice made underneath it.

## Context

Laravel has defaulted to SQLite since version 11, and a growing share of applications never leave it: desktop builds, NativePHP apps, single-tenant deployments, internal tools, CLI utilities, small SaaS products. Plenty of them are in production, with real users typing into a real search box.

When one of them needs search, the options are poor at both ends.

Scout's built-in `database` driver needs no infrastructure of its own: it searches the model's columns with `LIKE`, or through a full-text index you add and maintain yourself.

The engines that do index properly — Meilisearch, Typesense, Algolia — mean adopting a second datastore: a process to run, a schema to keep in sync, credentials to manage, a network hop in the request, a new failure mode, and for the hosted ones a bill. For an application whose entire database is one file on disk, that is a large amount of machinery for a search box.

Nothing occupies the middle, and the middle is where most of these applications live. The gap is not that good search is unavailable — it is that the cheapest good search available costs far more to adopt and operate than the application it is going into.

## Decision

Fill that middle: give Scout real indexed full-text search on SQLite, and treat cost of adoption and operation as the property to optimise, ahead of portability and ahead of feature breadth.

Concretely, "cheap and simple" was taken to mean:

- **Nothing new to run.** No daemon, no container, no API key, no network hop.
- **Nothing new to maintain.** No migration to publish and hand-edit, no index schema in version control — the models already declare what is searchable, so derive it from them.
- **Nothing new to learn.** The public surface is `Model::search()`. Everything is the Scout API an application already uses.
- **Nothing to tune before it works.** The defaults are meant to be usable as they are, including the typo tolerance.

## Consequences

The package is deliberately narrow. It is not competing with Meilisearch on features — there is no geo search, no synonyms, no facets, no distributed anything — and an application that outgrows it should move to a real search engine rather than expect this to grow into one. Swapping back out is a one-line change of `SCOUT_DRIVER`, which is the point of building on Scout.

Optimising for cheapness fixed the technology: SQLite already ships an inverted index, and using it beats hand-rolling one, at the price of running nowhere else. That trade is [decision 1](0001-sqlite-fts5-over-a-portable-index.md).

Optimising for simplicity fixed the workflow: index tables come from models rather than migrations, which is [decision 2](0002-tables-derived-from-models.md), and missing tables can be created on first write.

Optimising for "works without tuning" is why search is a cascade rather than one query with knobs — [decision 4](0004-a-cascade-of-passes.md) — and why the substring pass carries a threshold that a user should never have to think about, in [decision 5](0005-a-threshold-for-substring-matching.md).

The remaining records are consequences of those three.
