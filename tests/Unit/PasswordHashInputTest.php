<?php

namespace Tests\Unit;

use App\Rules\PasswordHashInput;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PasswordHashInputTest extends TestCase
{
    #[DataProvider('passwordInputs')]
    public function test_bcrypt_password_input_boundaries(string $password, bool $accepted): void
    {
        config(['hashing.driver' => 'bcrypt']);
        $validator = Validator::make(['password' => $password], ['password' => ['string', new PasswordHashInput]]);

        $this->assertSame($accepted, $validator->passes());
    }

    public static function passwordInputs(): array
    {
        return [
            ['customer-password', true],
            [str_repeat('a', 72), true],
            [str_repeat('é', 36), true],
            [str_repeat('a', 73), false],
            [str_repeat('é', 37), false],
            ["customer\0password", false],
        ];
    }
}
