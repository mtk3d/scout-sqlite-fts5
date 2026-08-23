<?php

declare(strict_types=1);

namespace Mtk3d\Scout\Fts5\Contracts;

/**
 * Normalizes text before it is indexed and before it is searched for.
 *
 * Both sides of the index go through the same implementation, so whatever a
 * normalizer does to stored content it also does to the incoming query.
 */
interface Normalizer
{
    /**
     * Returns the normalized form of the given text.
     */
    public function normalize(string $text): string;
}
