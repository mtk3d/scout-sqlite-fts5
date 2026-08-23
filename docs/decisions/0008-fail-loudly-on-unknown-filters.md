# 8. Throw on a filter that matches no column

**Status:** accepted

## Context

`Customer::search($term)->where('statuss', 'active')` names a column that does not exist. An engine can ignore the constraint, or refuse the query.

Ignoring is the friendlier-looking option and the more dangerous one. A filter is usually there to *remove* results — a tenant scope, an archived flag, a visibility check. Dropping it silently returns more than the caller asked for, which in the multi-tenant case means returning another tenant's records.

## Decision

Throw when a filter names a column that is neither an indexed filter nor a column on the model's table. Name the available columns in the message.

## Consequences

A typo breaks the request loudly, at the first search, instead of quietly widening the result set. The message lists what the field could have been, which turns most occurrences into a one-second fix.

This is deliberately asymmetric with the missing-index case, which returns *no* results rather than throwing. The reasoning is which way each failure is safe: a filter that vanishes shows too much, so it must fail; a missing index shows nothing, and breaking every page in the application on a dropped table is worse than an empty result.

Applications that pass user-supplied field names into `where()` must validate them first — though a search that filters on arbitrary user-named columns has a larger problem than this exception.
