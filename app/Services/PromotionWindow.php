<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PromotionWindow
{
    public static function parse(string $value, string $timezone, string $field): CarbonImmutable
    {
        PricingPolicy::requireTimezoneData($timezone);
        try {
            $zone = new DateTimeZone($timezone);
            if (preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2})(?::([0-9]{2}))?\z/D', $value, $parts) !== 1) {
                throw new \InvalidArgumentException;
            }
            $local = CarbonImmutable::createSafe((int) $parts[1], (int) $parts[2], (int) $parts[3], (int) $parts[4], (int) $parts[5], (int) ($parts[6] ?? 0), 'UTC');
            if ($local->year < 1000) {
                throw new \InvalidArgumentException;
            }
            $matches = [];
            $transitions = $zone->getTransitions($local->timestamp - 172800, $local->timestamp + 172800);
            foreach ($transitions ?: [] as $transition) {
                $candidate = $local->subSeconds($transition['offset']);
                if ($candidate->setTimezone($zone)->format('Y-m-d\TH:i:s') === $local->format('Y-m-d\TH:i:s')) {
                    if ($candidate->year < 1000 || $candidate->year > 9999) {
                        throw new \InvalidArgumentException;
                    }
                    $matches[$candidate->timestamp] = $candidate;
                }
            }
            if (count($matches) !== 1) {
                throw new \InvalidArgumentException;
            }

            return array_values($matches)[0];
        } catch (Throwable) {
            throw ValidationException::withMessages([$field => 'Date invalide, inexistante ou ambiguë dans ce fuseau. Choisissez une autre heure locale.']);
        }
    }
}
