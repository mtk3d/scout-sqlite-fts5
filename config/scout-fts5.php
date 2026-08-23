<?php

declare(strict_types=1);

use ScoutFts5\Normalizer\DiacriticsNormalizer;

return [

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | The connection holding the FTS5 virtual tables. It must be an SQLite
    | connection compiled with FTS5 support, and it should be the same
    | connection as your models so ordering and filtering can join.
    |
    | `null` uses the application's default connection.
    |
    */

    'connection' => env('SCOUT_FTS5_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Index Table Suffix
    |--------------------------------------------------------------------------
    |
    | Every searchable model gets one virtual table named after its
    | `searchableAs()` value plus this suffix, e.g. `customers_fts`.
    |
    */

    'suffix' => '_fts',

    /*
    |--------------------------------------------------------------------------
    | FTS5 Tokenizer
    |--------------------------------------------------------------------------
    |
    | Passed verbatim to `CREATE VIRTUAL TABLE ... tokenize='...'`. The default
    | folds diacritics, so "łódź" and "lodz" are the same token. Changing this
    | requires rebuilding the indexes (`php artisan scout:fts5-rebuild`).
    |
    | @see https://www.sqlite.org/fts5.html#tokenizers
    |
    */

    'tokenizer' => 'unicode61 remove_diacritics 2',

    /*
    |--------------------------------------------------------------------------
    | Automatic Index Creation
    |--------------------------------------------------------------------------
    |
    | When enabled, a missing virtual table is created on the fly the first time
    | a model is indexed. Disable it if you would rather manage the schema
    | explicitly with `php artisan scout:fts5-create`.
    |
    */

    'auto_create' => true,

    /*
    |--------------------------------------------------------------------------
    | Text Normalizer
    |--------------------------------------------------------------------------
    |
    | Applied to both indexed content and incoming queries, so the two always
    | agree. The default lowercases and folds Latin diacritics, which is what
    | makes the `LIKE` substring fallback work on accented text.
    |
    | Implement `ScoutFts5\Contracts\Normalizer` for your own.
    |
    */

    'normalizer' => DiacriticsNormalizer::class,

    /*
    |--------------------------------------------------------------------------
    | Search Passes
    |--------------------------------------------------------------------------
    |
    | Search runs as a cascade: each pass is tried in order and the first one
    | that returns anything wins. Turning a pass off makes search stricter
    | and faster. See the README for what each one does.
    |
    |   prefix  — every word must match as a prefix   ("jan* kowalski*")
    |   typo    — same, with the last characters cut  ("j* kowalsk*")
    |   any     — one matching word is enough         ("jan* OR kowalski*")
    |   trigram — substring match anywhere in content (LIKE '%owal%')
    |
    */

    'passes' => [
        'prefix' => true,
        'typo' => true,
        'any' => true,
        'trigram' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Typo Pass Tuning
    |--------------------------------------------------------------------------
    |
    | `trim` is how many characters are cut from the end of each word, and
    | `min_prefix` is the shortest prefix the pass will ever search for.
    |
    */

    'typo' => [
        'trim' => 2,
        'min_prefix' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Trigram Pass Tuning
    |--------------------------------------------------------------------------
    |
    | The last-resort pass scans content with `LIKE`, so it is the slowest one.
    | `max_grams` caps how many substrings a single query may produce, and
    | `min_ratio` is the share of one word's substrings a document has to
    | contain before it counts as a match.
    |
    | Lower `min_ratio` to catch heavier misspellings, raise it if nonsense
    | queries are coming back with results.
    |
    */

    'trigram' => [
        'size' => 3,
        'max_grams' => 24,
        'min_ratio' => 0.4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Discovery
    |--------------------------------------------------------------------------
    |
    | Where the `scout:fts5-*` commands look for searchable models when you do
    | not name one explicitly. Add paths, or list model classes directly.
    |
    */

    'model_paths' => [
        app_path('Models'),
    ],

    'models' => [],

];
