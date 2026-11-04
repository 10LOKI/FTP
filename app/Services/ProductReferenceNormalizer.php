<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Normalisation V1 : casse et espaces uniquement, ponctuation et zéros conservés.
 * Tout changement de cette règle exige une migration de reconstruction des clés.
 */
final class ProductReferenceNormalizer
{
    public static function normalize(string $reference): string
    {
        if (! mb_check_encoding($reference, 'UTF-8')) {
            throw new InvalidArgumentException('Invalid UTF-8 reference.');
        }

        return mb_strtoupper(preg_replace('/[\p{Z}\s]+/u', '', $reference), 'UTF-8');
    }

    public static function searchKey(string $reference): string
    {
        return hash('sha256', self::normalize($reference));
    }

    /** @return array{normalized_reference: string, reference_search_key: string, reference_exact_key: string} */
    public static function attributes(string $reference): array
    {
        return [
            'normalized_reference' => self::normalize($reference),
            'reference_search_key' => self::searchKey($reference),
            'reference_exact_key' => hash('sha256', $reference),
        ];
    }
}
