<?php

namespace Tests\Unit;

use App\Services\PromotionWindow;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PromotionWindowTest extends TestCase
{
    public function test_local_time_is_converted_to_utc_with_its_actual_offset(): void
    {
        $this->assertSame('2026-11-05T09:30:00+00:00', PromotionWindow::parse('2026-11-05T09:30', 'Africa/Casablanca', 'starts_at')->toIso8601String());
    }

    #[DataProvider('invalidDates')]
    public function test_impossible_and_ambiguous_local_times_are_refused(string $input, string $timezone): void
    {
        $this->expectException(ValidationException::class);

        PromotionWindow::parse($input, $timezone, 'starts_at');
    }

    public static function invalidDates(): array
    {
        return ['invalid date' => ['2026-02-30T12:00', 'UTC'], 'spring gap' => ['2026-03-29T02:30', 'Europe/Paris'], 'autumn ambiguity' => ['2026-10-25T02:30', 'Europe/Paris'], 'bad timezone' => ['2026-11-05T12:00', 'Unknown/Zone']];
    }
}
