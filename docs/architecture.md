# Architecture

[← back to the README](https://github.com/mtk3d/scout-sqlite-fts5#readme)

The package is small, but it sits between three things that each have opinions — Eloquent, Scout and FTS5 — so it helps to see where the boundaries fall. The diagrams below follow the [C4 model](https://c4model.com/), zooming in one level at a time.

## Level 1 — System context

```mermaid
C4Context
    title Searching in an application that uses this driver

    Person(user, "Application user", "Types a query into a search box")
    Person(dev, "Developer", "Declares what is searchable")

    System(app, "Laravel application", "Calls Model::search() and never touches the index directly")
    SystemDb(db, "SQLite database file", "Application tables and their full-text index, in one file")

    Rel(user, app, "Searches")
    Rel(dev, app, "toSearchableArray(), searchableFilters()")
    Rel(app, db, "Queries and indexes", "PDO")
```

The point of the whole package is what is *missing* from this picture: there is no search service. With Meilisearch, Typesense or Algolia there would be a second system here, with its own process, network hop, credentials and failure modes. Here the index is a set of tables in the database file the application already opens.

## Level 2 — Containers

```mermaid
C4Container
    title Inside the Laravel application

    Person(user, "Application user")

    Container_Boundary(app, "Laravel application") {
        Container(models, "Eloquent models", "PHP", "Use the Searchable trait and declare what to index")
        Container(scout, "Laravel Scout", "PHP", "Resolves the engine and observes model events")
        Container(driver, "scout-sqlite-fts5", "PHP", "Turns Scout's calls into SQL")
    }

    ContainerDb(tables, "Model tables", "SQLite", "customers, orders, invoices")
    ContainerDb(fts, "FTS5 virtual tables", "SQLite", "customers_fts, orders_fts, invoices_fts")

    Rel(user, models, "Model::search()")
    Rel(models, scout, "Saves and deletes raise events")
    Rel(scout, driver, "update(), delete(), search(), paginate()")
    Rel(driver, fts, "MATCH, bm25(), INSERT, DELETE")
    Rel(driver, tables, "Joins for ordering and undeclared filters")
    Rel(scout, tables, "Hydrates the models that matched")
```

Two arrows in that diagram carry most of the design.

The first is `driver → tables`. The index is not a separate world: it lives in the same file as the data, which is why the driver can join the model's own table to answer a filter on a column that was never indexed, or to sort by one. An engine talking to a remote service cannot do that — it would have to either refuse the query or fetch everything and sort in PHP.

The second is `scout → tables`. The driver returns keys, not models. Hydration is Scout's job, and it applies whatever constraints the caller attached with `query()`.

## Level 3 — Components

```mermaid
C4Component
    title Inside the driver

    Container(scout, "Laravel Scout", "PHP", "Calls the engine")

    Component(engine, "Engine", "Scout Engine", "The entry point Scout knows about; delegates and preserves result order")
    Component(indexer, "Indexer", "Write path", "Flattens searchable data and writes documents")
    Component(seeker, "Seeker", "Read path", "Runs the cascade, filters, orders, paginates")
    Component(schema, "Support\\Schema", "DDL", "Names, creates and inspects virtual tables")
    Component(pass, "Support\\SearchPass", "Strategy", "One attempt: how to constrain, how to rank")
    Component(query, "Support\\MatchQuery", "Escaping", "Quotes words as FTS5 phrases")
    Component(tokens, "Support\\Tokens", "Text", "Splits words, shortens them, builds substrings")
    Component(norm, "Normalizer", "Contract", "Folds text the same way on both sides of the index")
    Component(config, "SearchConfiguration", "Settings", "Typed view over the config array")

    ContainerDb(fts, "FTS5 virtual tables", "SQLite")

    Rel(scout, engine, "search(), update(), delete()")
    Rel(engine, indexer, "Writes")
    Rel(engine, seeker, "Reads")
    Rel(indexer, norm, "Normalizes content")
    Rel(indexer, schema, "Creates tables on demand")
    Rel(seeker, tokens, "Splits the query")
    Rel(seeker, pass, "Builds the cascade")
    Rel(pass, query, "Escapes words")
    Rel(seeker, config, "Reads tuning")
    Rel(indexer, fts, "INSERT, DELETE")
    Rel(seeker, fts, "SELECT … MATCH")
```

The split that matters is `Indexer` and `Seeker`: the write path and the read path share nothing but the `Schema` that names their tables and the `Normalizer` that has to fold text identically on both sides. Everything under `Support` is free of framework imports and could be tested without booting an application.

## A search, end to end

```mermaid
sequenceDiagram
    autonumber
    participant App as Application
    participant Scout as Laravel Scout
    participant Seeker
    participant SQLite

    App->>Scout: Customer::search('kowalsky')->paginate(20)
    Scout->>Seeker: paginate(builder, 20, 1)
    Seeker->>Seeker: normalize, split into words

    rect rgb(240, 240, 240)
        note over Seeker,SQLite: pass 1 — every word as a prefix
        Seeker->>SQLite: COUNT … MATCH '"kowalsky"*'
        SQLite-->>Seeker: 0
    end

    rect rgb(240, 240, 240)
        note over Seeker,SQLite: pass 2 — shortened prefix
        Seeker->>SQLite: COUNT … MATCH '"kowals"*'
        SQLite-->>Seeker: 7
        Seeker->>SQLite: SELECT rowid … ORDER BY bm25() LIMIT 20
        SQLite-->>Seeker: 7 keys, best match first
    end

    Seeker-->>Scout: SearchResult(keys, total: 7, pass: 'typo')
    Scout->>SQLite: SELECT * FROM customers WHERE id IN (…)
    SQLite-->>Scout: models
    Scout-->>App: LengthAwarePaginator, in relevance order
```

Passes three and four never run: the cascade stops at the first one that matches. A query that finds an exact hit costs one `COUNT` and one `SELECT`; only a query that finds nothing pays for every interpretation, ending in the substring scan.

The count is a separate statement from the page. That is what lets the paginator report seven matches while returning at most twenty rows — and what keeps ordering and slicing in SQL rather than in PHP.
