<?php

namespace Tests\Unit;

use App\Services\PriceAmount;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PriceAmountTest extends TestCase
{
    #[DataProvider('amounts')]
    public function test_decimal_text_converts_to_exact_minor_units_and_back(string $input, int $expected, string $display): void
    {
        $this->assertSame($expected, PriceAmount::minorUnits($input));
        $this->assertSame($display, PriceAmount::decimal($expected));
    }

    public static function amounts(): array
    {
        return ['zero' => ['0', 0, '0.00'], 'cent' => ['0.01', 1, '0.01'], 'whole' => ['100', 10000, '100.00'], 'one decimal' => ['123.4', 12340, '123.40'], 'maximum' => ['9999999999.99', 999999999999, '9999999999.99']];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_or_inexact_amounts_are_refused(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        PriceAmount::minorUnits($value);
    }

    public static function invalidAmounts(): array
    {
        return array_map(fn (string $value): array => [$value], ['-1', '1e2', '1,50', '12.345', '10000000000.00', 'NaN', ' 1', '01.00']);
    }
}
