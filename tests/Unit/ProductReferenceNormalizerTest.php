<?php

namespace Tests\Unit;

use App\Services\ProductReferenceNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProductReferenceNormalizerTest extends TestCase
{
    #[DataProvider('referenceCases')]
    public function test_normalization_preserves_everything_except_case_and_whitespace(string $raw, string $expected): void
    {
        $this->assertSame($expected, ProductReferenceNormalizer::normalize($raw));
    }

    /** @return array<string, array{string, string}> */
    public static function referenceCases(): array
    {
        return [
            'leading zeros' => ['0343012', '0343012'],
            'case and spaces' => [' cr f 200028 ', 'CRF200028'],
            'nonbreaking and thin spaces' => ["03\u{00A0}434\u{2009}3010\t00", '03434301000'],
            'dot and case' => ['566830.h195', '566830.H195'],
            'other separators' => ['00-01/ab_c.2', '00-01/AB_C.2'],
            'no transliteration' => ['réf-001', 'RÉF-001'],
            'no width conversion' => ['０００１', '０００１'],
            'zero width is not whitespace' => ["A\u{200B}B", "A\u{200B}B"],
        ];
    }

    public function test_invalid_utf8_is_refused_instead_of_silently_changing_a_code(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProductReferenceNormalizer::normalize("\xFF");
    }
}
