# 5. Require a share of one word's substrings

**Status:** accepted

## Context

The last pass exists to catch typos in the middle of a word, where no prefix query can reach — `kowerlski` shares no usable prefix with `kowalski`. Comparing three-character substrings finds it.

The naive form of that pass — match if the content contains *any* of the query's substrings — matches nearly everything. `zupelnie inne slowa` has nothing to do with `Jan Kowalski`, but `kowalski` contains `owa`, and one shared substring was enough. This was found by a test asserting that a nonsense query returns nothing, and it did not.

A threshold across the whole query does not separate the two either. The real typo shares 3 of 7 substrings (43%); the nonsense query shares 1 of 12 (8%) — but that 1 belongs to a single word, and averaging it across the query hides which word it came from.

## Decision

Group substrings by the word they came from, and match a document when it contains enough of a *single* word's substrings — 40% by default.

## Consequences

The two cases separate cleanly. A misspelled word keeps most of its own substrings; an unrelated query shares a stray one or two of any single word's.

| Query | Best word | Result |
|---|---|---|
| `kowerlski` | 3 of 7 (43%) | matches |
| `zupelnie inne slowa` | 1 of 3 (33%) | no match |

The margin is not enormous, and the threshold is exposed as `trigram.min_ratio` because the right value depends on the language and the length of the indexed fields.

Words shorter than the substring size are skipped rather than matched whole, since a two-character substring matches almost anything. This is why a two-character query that matches no token returns nothing — and why CJK, whose words are often two characters, needs `trigram.size` lowered to be reachable this way.
