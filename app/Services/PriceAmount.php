<?php

namespace App\Services;

use InvalidArgumentException;

final class PriceAmount
{
    public static function minorUnits(string $value): int
    {
        if (preg_match('/\A(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?\z/D', $value, $parts) !== 1) {
            throw new InvalidArgumentException('Expected a non-negative decimal amount with at most two decimal places.');
        }

        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    public static function decimal(int $minorUnits): string
    {
        if ($minorUnits < 0) {
            throw new InvalidArgumentException('A price cannot be negative.');
        }

        return intdiv($minorUnits, 100).'.'.str_pad((string) ($minorUnits % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function discounted(int $minorUnits, string $percentage): int
    {
        $basisPoints = self::minorUnits($percentage);
        if ($basisPoints > 10000 || $minorUnits < 0 || $minorUnits > 999999999999) {
            throw new InvalidArgumentException('Invalid discount or price.');
        }

        return intdiv($minorUnits * (10000 - $basisPoints) + 5000, 10000);
    }
}
