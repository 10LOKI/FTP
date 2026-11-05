<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class PricingPolicy
{
    public static function requireApproved(): void
    {
        if (! config('pricing.approved') || config('pricing.discount_policy') !== 'best_price' || config('pricing.rounding') !== 'half_up_2' || config('pricing.overlap_policy') !== 'reject_enabled') {
            throw ValidationException::withMessages(['state' => 'Les règles commerciales D08/D18 doivent être approuvées avant activation.']);
        }
        self::requireTimezoneData(config('pricing.timezone'));
    }

    /**
     * Require Morocco's historical 2026 change from IANA 2026c or later.
     * The actual windows always use the named timezone, never a fixed offset.
     * Source: https://data.iana.org/time-zones/tzdb-2026c/NEWS
     */
    public static function requireTimezoneData(string $timezone): void
    {
        if ($timezone === 'Africa/Casablanca' && (new DateTimeZone($timezone))->getOffset(new DateTimeImmutable('2026-11-01T00:00:00Z')) !== 0) {
            throw new ServiceUnavailableHttpException(null, 'Le calcul des dates des offres est momentanément indisponible.');
        }
    }
}
