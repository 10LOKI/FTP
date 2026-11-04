<?php

namespace App\Rules;

use App\Services\PriceAmount;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

class DecimalPrice implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            if (! is_string($value)) {
                throw new InvalidArgumentException;
            }
            PriceAmount::minorUnits($value);
        } catch (InvalidArgumentException) {
            $fail('Utilisez un montant positif ou nul en texte décimal, avec au plus deux chiffres après le point.');
        }
    }
}
