<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class ReferenceCode implements ValidationRule
{
    public bool $implicit = true;

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            $fail('La référence doit être un texte UTF-8 valide sans caractère nul.');

            return;
        }

        if (preg_match('/[^\p{Z}\s]/u', $value) !== 1) {
            $fail('La référence doit contenir au moins un caractère autre qu’un espace.');
        }
    }
}
