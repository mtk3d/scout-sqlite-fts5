# 3. Store integer keys in `rowid`

**Status:** accepted

## Context

An FTS5 table has no indexes of its own. Every column is either tokenized into the full-text index or `UNINDEXED`, and neither can be looked up the way a b-tree column can. Storing the document key in an ordinary column means every update and delete scans the table.

It does have a `rowid`, though, and a `rowid` lookup is a b-tree lookup. It only accepts integers.

## Decision

Store the key in `rowid` when the model's key is an integer. Fall back to an explicit `doc_id UNINDEXED` column when it is a string.

## Consequences

The common case is fast. Reindexing one model after a save is `DELETE FROM t WHERE rowid = ?` followed by an insert, both logarithmic.

UUID and ULID models still work, but pay a scan per write. It is invisible at small scale and matters during a bulk import, which is why the documentation points those users at `scout:fts5-rebuild` — importing into a table with nothing in it has nothing to delete.

The two layouts are decided per model from `getKeyType()`, so nothing about the choice needs to be stored or configured. A model that overrides `getScoutKey()` to return a string while declaring an integer key type would break this; that combination is not supported.
