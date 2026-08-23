<?php

declare(strict_types=1);

namespace Wrenchr\Scout\Fts5;

/**
 * A typed view over the `scout-fts5` config array.
 */
class SearchConfiguration
{
    /**
     * @param  array<string, bool>  $passes
     */
    public function __construct(
        private string $suffix = '_fts',
        private string $tokenizer = 'unicode61 remove_diacritics 2',
        private bool $autoCreate = true,
        private array $passes = ['prefix' => true, 'typo' => true, 'any' => true, 'trigram' => true],
        private int $typoTrim = 2,
        private int $typoMinPrefix = 3,
        private int $trigramSize = 3,
        private int $trigramMaxGrams = 24,
        private float $trigramMinRatio = 0.4,
    ) {}

    /**
     * Builds the configuration from a published config array, falling back to
     * the packaged defaults for anything the application left out.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        $defaults = new self;

        return new self(
            suffix: $config['suffix'] ?? $defaults->suffix,
            tokenizer: $config['tokenizer'] ?? $defaults->tokenizer,
            autoCreate: $config['auto_create'] ?? $defaults->autoCreate,
            passes: array_merge($defaults->passes, $config['passes'] ?? []),
            typoTrim: (int) ($config['typo']['trim'] ?? $defaults->typoTrim),
            typoMinPrefix: (int) ($config['typo']['min_prefix'] ?? $defaults->typoMinPrefix),
            trigramSize: (int) ($config['trigram']['size'] ?? $defaults->trigramSize),
            trigramMaxGrams: (int) ($config['trigram']['max_grams'] ?? $defaults->trigramMaxGrams),
            trigramMinRatio: (float) ($config['trigram']['min_ratio'] ?? $defaults->trigramMinRatio),
        );
    }

    /**
     * The suffix appended to `searchableAs()` to name the virtual table.
     */
    public function suffix(): string
    {
        return $this->suffix;
    }

    /**
     * The FTS5 tokenizer definition used when creating virtual tables.
     */
    public function tokenizer(): string
    {
        return $this->tokenizer;
    }

    /**
     * Whether missing index tables are created on the fly during indexing.
     */
    public function shouldAutoCreate(): bool
    {
        return $this->autoCreate;
    }

    /**
     * Whether the given search pass is enabled.
     */
    public function passEnabled(string $pass): bool
    {
        return (bool) ($this->passes[$pass] ?? false);
    }

    /**
     * How many characters the typo pass cuts from the end of each word.
     */
    public function typoTrim(): int
    {
        return max(1, $this->typoTrim);
    }

    /**
     * The shortest prefix the typo pass will search for.
     */
    public function typoMinPrefix(): int
    {
        return max(1, $this->typoMinPrefix);
    }

    /**
     * The length of the substrings produced by the trigram pass.
     */
    public function trigramSize(): int
    {
        return max(2, $this->trigramSize);
    }

    /**
     * The maximum number of substrings a single trigram query may produce.
     */
    public function trigramMaxGrams(): int
    {
        return max(1, $this->trigramMaxGrams);
    }

    /**
     * The share of a word's substrings a document must contain to be
     * considered a substring match.
     */
    public function trigramMinRatio(): float
    {
        return min(1.0, max(0.0, $this->trigramMinRatio));
    }
}
