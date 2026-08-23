<?php

declare(strict_types=1);

namespace ScoutFts5;

use Illuminate\Contracts\Support\Arrayable;
use Laravel\Scout\Builder;

/**
 * The outcome of a search: the matching document keys in relevance order, how
 * many documents matched in total, and which pass of the search cascade
 * produced them.
 */
class SearchResult implements Arrayable
{
    /**
     * @param  array<int, mixed>  $ids
     */
    public function __construct(
        private Builder $builder,
        private array $ids,
        private ?int $total = null,
        private ?string $pass = null,
    ) {
        $this->total ??= count($ids);
    }

    /**
     * An empty result for the given search.
     */
    public static function empty(Builder $builder): self
    {
        return new self($builder, [], 0);
    }

    /**
     * The builder the search was performed with.
     */
    public function builder(): Builder
    {
        return $this->builder;
    }

    /**
     * The keys of the matching documents, best match first.
     *
     * This holds one page worth of keys when the search was paginated, while
     * {@see total()} always reports every document that matched.
     *
     * @return array<int, mixed>
     */
    public function ids(): array
    {
        return $this->ids;
    }

    /**
     * The number of documents that matched, ignoring pagination.
     */
    public function total(): int
    {
        return $this->total;
    }

    /**
     * Which pass of the cascade produced these results: `prefix`, `typo`,
     * `any`, `trigram`, or `null` when nothing matched. Useful for telling a
     * user that you fell back to a fuzzy interpretation of their query.
     */
    public function pass(): ?string
    {
        return $this->pass;
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return [
            'ids' => $this->ids,
            'total' => $this->total,
            'pass' => $this->pass,
        ];
    }
}
