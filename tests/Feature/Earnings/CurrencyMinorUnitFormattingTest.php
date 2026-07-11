<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Models\Currency;
use App\Support\MoneyFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Phase 15.1 §6 — currency-aware minor-unit handling. The exponent
 * comes from the canonical currencies.minor_units column, never a
 * hardcoded "divide by 100", and no PHP float ever touches a canonical
 * amount in either direction.
 */
class CurrencyMinorUnitFormattingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MoneyFormatter::flushCache();

        foreach ([['INR', 2], ['USD', 2], ['JPY', 0], ['KWD', 3]] as [$code, $units]) {
            Currency::query()->firstOrCreate(['code' => $code], [
                'name' => $code.' test currency', 'symbol' => $code,
                'numeric_code' => (string) random_int(100, 999),
                'minor_units' => $units, 'status' => 'active', 'sort_order' => 1,
            ]);
        }
    }

    public function test_two_decimal_currency_formats_from_canonical_exponent(): void
    {
        $this->assertSame('12,345.67 INR', MoneyFormatter::format(1234567, 'INR'));
        $this->assertSame('0.05 USD', MoneyFormatter::format(5, 'USD'));
    }

    public function test_zero_decimal_currency_shows_no_fraction(): void
    {
        $this->assertSame('1,250 JPY', MoneyFormatter::format(1250, 'JPY'));
        $this->assertSame('0 JPY', MoneyFormatter::format(0, 'JPY'));
    }

    public function test_three_decimal_currency_shows_three_places(): void
    {
        $this->assertSame('3.141 KWD', MoneyFormatter::format(3141, 'KWD'));
        $this->assertSame('1,000.500 KWD', MoneyFormatter::format(1000500, 'KWD'));
    }

    public function test_major_to_minor_round_trip_per_exponent(): void
    {
        foreach ([['1500.00', 'INR', 150000], ['1250', 'JPY', 1250], ['3.141', 'KWD', 3141], ['0.05', 'USD', 5]] as [$input, $code, $minor]) {
            $units = MoneyFormatter::minorUnitsFor($code);
            $converted = MoneyFormatter::toMinor($input, $units);

            $this->assertSame($minor, $converted, "{$input} {$code}");
            // Round trip: format → strip grouping/code → toMinor → same.
            $display = str_replace([',', ' '.$code], '', MoneyFormatter::format($converted, $code));
            $this->assertSame($minor, MoneyFormatter::toMinor($display, $units));
        }
    }

    public function test_excess_precision_is_rejected_never_truncated(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MoneyFormatter::toMinor('10.5', MoneyFormatter::minorUnitsFor('JPY'));
    }

    public function test_excess_precision_is_rejected_for_three_decimal_currency(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MoneyFormatter::toMinor('1.2345', MoneyFormatter::minorUnitsFor('KWD'));
    }

    public function test_malformed_amounts_are_rejected(): void
    {
        foreach (['', 'abc', '-5', '1.2.3', '1e3'] as $bad) {
            try {
                MoneyFormatter::toMinor($bad, 2);
                $this->fail("'{$bad}' should have been rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_no_floating_point_precision_errors_at_large_magnitudes(): void
    {
        // 2^53 + 1 minor units is unrepresentable as a float — string math
        // must render it exactly.
        $this->assertSame('90,071,992,547,409.93 INR', MoneyFormatter::format(9007199254740993, 'INR'));

        // The classic float trap: 0.29 → 28.999… under float multiply.
        $this->assertSame(29, MoneyFormatter::toMinor('0.29', 2));
        $this->assertSame(4015, MoneyFormatter::toMinor('40.15', 2));
    }

    public function test_unsupported_exponents_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MoneyFormatter::toMinor('1.00', 9);
    }

    public function test_unknown_currency_defaults_conservatively_to_two(): void
    {
        $this->assertSame(2, MoneyFormatter::minorUnitsFor('ZZZ'));
    }
}
