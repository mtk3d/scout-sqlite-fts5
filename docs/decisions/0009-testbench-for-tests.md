# 9. Test through a booted Laravel application

**Status:** accepted

## Context

Most of this package only exists in the presence of a framework. `Model::search()` goes through Scout's `EngineManager`, indexing is triggered by Eloquent model events, configuration comes from the config repository, and four of the moving parts are artisan commands.

Testing that without a Laravel application means either building a container, a config repository and an Eloquent bootstrap by hand — more code than the tests, and drifting from real behaviour as the framework changes — or testing only the parts that need no framework and leaving the interesting behaviour uncovered.

## Decision

Use `orchestra/testbench`, which boots a minimal Laravel application in-process, and test through the same surface an application uses: create a model, search for it.

## Consequences

The tests exercise the real path — the service provider registers the driver, saving a model triggers indexing through Scout's observer, `$this->artisan()` runs the commands through a real kernel.

The Testbench version pins the framework version, which is what makes the CI matrix possible: `^10.0` tests against Laravel 12 and `^11.0` against Laravel 13, from the same test suite.

The whole framework becomes a development dependency. It does not ship — `.gitattributes` keeps `tests/` out of the distribution, verified by installing the published package and listing what arrived.

Four classes — `Tokens`, `MatchQuery`, `DiacriticsNormalizer` and `SearchConfiguration` — have no framework imports and are currently covered only indirectly, through a booted application and a real database. Testing them directly would be faster and more precise, and is worth doing.
