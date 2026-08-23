<?php

declare(strict_types=1);

namespace Wrenchr\Scout\Fts5\Normalizer;

use Wrenchr\Scout\Fts5\Contracts\Normalizer;

/**
 * Lowercases text and folds Latin diacritics down to their ASCII base letter.
 *
 * FTS5 already folds diacritics inside the index when the `unicode61
 * remove_diacritics 2` tokenizer is used, but the `LIKE` based trigram
 * fallback does not go through the tokenizer. Folding in PHP as well keeps
 * both search paths agreeing on what "łódź" looks like.
 */
class DiacriticsNormalizer implements Normalizer
{
    /**
     * Characters replaced before lowercasing, mapped to their ASCII counterpart.
     *
     * Covers the Latin ranges people actually type into a search box: Polish,
     * German, the Nordics, Czech/Slovak, Hungarian, Romanian and the Romance
     * languages.
     */
    private const REPLACEMENTS = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
        'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a',
        'ç' => 'c', 'č' => 'c', 'ď' => 'd', 'đ' => 'd',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ě' => 'e', 'ē' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i',
        'ñ' => 'n', 'ň' => 'n',
        'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o', 'ő' => 'o',
        'ř' => 'r', 'š' => 's', 'ș' => 's', 'ß' => 'ss',
        'ť' => 't', 'ț' => 't',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ů' => 'u', 'ű' => 'u', 'ū' => 'u',
        'ý' => 'y', 'ÿ' => 'y', 'ž' => 'z',
        'æ' => 'ae', 'œ' => 'oe',
    ];

    /**
     * {@inheritDoc}
     */
    public function normalize(string $text): string
    {
        $lowered = mb_strtolower($text);

        return strtr($lowered, self::REPLACEMENTS);
    }
}
