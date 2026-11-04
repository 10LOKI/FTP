<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PasswordHashInput implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (str_contains($value, "\0")) {
            $fail('The :attribute must not contain null bytes.');

            return;
        }

        if (config('hashing.driver') === 'bcrypt' && strlen($value) > 72) {
            $fail('The :attribute must not exceed 72 bytes with bcrypt hashing.');
        }
    }
}
