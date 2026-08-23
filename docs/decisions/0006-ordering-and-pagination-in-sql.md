# 6. Order, filter and paginate in SQL

**Status:** accepted

## Context

A Scout engine returns keys; Scout hydrates them into models. The tempting shortcut is to fetch every matching key, slice the page in PHP, and let the caller's `orderBy()` sort the models that came back.

That shortcut is wrong in a way that is easy to miss in testing. Sorting the current page sorts one arbitrary slice of the results — so a record can appear on two pages, or on none, and the pages do not add up to the result set. It only shows up once someone clicks through to page two with an ordering applied.

Filters have a related problem. Scout's `where()` can only be answered by the index, so an engine either supports filtering on indexed columns and ignores the rest, or refuses.

## Decision

Push ordering, filtering and pagination into the query against the index. Answer filters on declared columns from the index table, and join the model's own table for anything else — including every explicit ordering.

## Consequences

Pages are consistent with the order that produced them, and the reported total is every document that matched rather than the size of the current page.

Filters on columns that were never indexed work, because the index is in the same SQLite file as the data and the join is local. Nothing is silently dropped.

This is what ties the package to a single connection: the index and the models must be in the same database for the join to be possible. That constraint is checked at boot and stated in the documentation.

Relevance order survives hydration — the engine sorts the models back into the order the ranking produced, rather than letting `whereIn` return them in whatever order the database prefers.

An explicit `orderBy()` replaces relevance ordering rather than supplementing it, which is Scout's contract. Applications that add `latest()` out of habit lose the ranking they are paying for.
