# 7. Quote every word as an FTS5 phrase

**Status:** accepted

## Context

The contents of a `MATCH` expression are not a string to be searched for. FTS5 parses them as a query language with its own operators — `AND`, `OR`, `NOT`, `NEAR`, parentheses, quotes, a `-` prefix and a `*` suffix.

Passing a user's input straight through means they are writing queries rather than searching. Most of the time that produces surprises: `kowalski OR nowak` returns everyone named Nowak, and `NEAR(jan)` returns a syntax error rather than results. Binding the expression as a statement parameter prevents SQL injection but does nothing about this — the parameter's *contents* are still parsed as FTS5.

## Decision

Emit each word as a quoted FTS5 phrase, doubling any quote inside it, and append the prefix star outside the quotes:

```
"jan"* AND "kowalsky"*
```

## Consequences

Query syntax the user typed is searched for literally. `kowalski OR nowak` looks for the word *or*; a stray `"` is a character. The test that pins this asserts the query falls through to the `any` pass — proof that the strict pass did not interpret `OR` as an operator.

Users cannot write FTS5 queries. Nobody can type `title:foo` or `NEAR(a b, 5)` and have it work. For a search box that is the right trade; an application that wants an advanced syntax has to build it on top and construct its own expressions.

The escaping lives in one class, `Support\MatchQuery`, so there is a single place where the boundary between user text and query language is crossed.
