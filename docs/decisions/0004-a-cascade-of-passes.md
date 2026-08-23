# 4. Answer a query with a cascade of passes

**Status:** accepted

## Context

Users misspell things, and a search box that only finds exact prefixes feels broken. The usual answers are a fuzzy engine that scores edit distance, or one clever query that is simultaneously strict and forgiving.

The second does not exist. A query loose enough to find `kowalsky` for `Kowalski` is loose enough to find twenty other people too, and it applies that looseness to the queries that were spelled correctly in the first place.

## Decision

Try several queries in sequence, from strictest to loosest, and stop at the first that matches anything: every word as a prefix, then shortened prefixes, then any word, then substrings.

## Consequences

Precision is preserved where it exists. A query that matches exactly is answered by the strict pass and never sees the fuzzy ones — the looseness only applies when strictness found nothing.

Cost follows the same shape. An exact query is one indexed `MATCH`; only a query that finds nothing pays for all four, and the expensive scan is last.

The result reports which pass answered, so an application can tell the user it guessed rather than silently returning something they did not ask for.

Passes that would repeat an earlier query are skipped: words too short to shorten make the second pass identical to the first, and a single-word query makes "any word" identical to "every word".

The cost is four round trips in the worst case, and four behaviours to understand instead of one. Each pass can be turned off in configuration for applications that would rather return nothing than guess.
