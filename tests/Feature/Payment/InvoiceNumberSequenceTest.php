<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Models\InvoiceNumberSequence;
use App\Services\Payment\InvoiceNumberAllocator;
use App\Settings\InvoiceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * SRS §14.22: numbering is unique, sequential, and its format is
 * configurable. Row-lock discipline mirrors WalletLedgerService's own
 * already-proven-safe convention rather than introducing a new one, so
 * these tests focus on format/uniqueness/validation correctness rather
 * than re-proving MySQL row-locking itself.
 */
class InvoiceNumberSequenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_format_matches_the_srs_example_shape(): void
    {
        $number = app(InvoiceNumberAllocator::class)->allocate();

        $this->assertSame('STEM/INV/'.now()->year.'/000001', $number);
    }

    public function test_repeated_allocation_increments_sequentially_and_never_repeats(): void
    {
        $allocator = app(InvoiceNumberAllocator::class);

        $numbers = [$allocator->allocate(), $allocator->allocate(), $allocator->allocate()];

        $this->assertSame([
            'STEM/INV/'.now()->year.'/000001',
            'STEM/INV/'.now()->year.'/000002',
            'STEM/INV/'.now()->year.'/000003',
        ], $numbers);
        $this->assertCount(3, array_unique($numbers));
    }

    public function test_allocation_is_scoped_to_the_current_year(): void
    {
        app(InvoiceNumberAllocator::class)->allocate();

        $sequence = InvoiceNumberSequence::query()->sole();
        $this->assertSame((string) now()->year, $sequence->scope_key);
        $this->assertSame(2, $sequence->next_number);
    }

    public function test_configured_prefix_and_digit_width_are_honored(): void
    {
        $settings = app(InvoiceSettings::class);
        $settings->number_prefix = 'ACME/RCPT';
        $settings->sequence_digits = 4;
        $settings->save();

        $number = app(InvoiceNumberAllocator::class)->allocate();

        $this->assertSame('ACME/RCPT/'.now()->year.'/0001', $number);
    }

    public function test_a_format_missing_the_sequence_token_fails_safely(): void
    {
        $settings = app(InvoiceSettings::class);
        $settings->number_format = '{prefix}/{year}';
        $settings->save();

        $this->expectException(RuntimeException::class);
        app(InvoiceNumberAllocator::class)->allocate();
    }

    public function test_a_non_positive_digit_width_is_clamped_rather_than_breaking_generation(): void
    {
        $settings = app(InvoiceSettings::class);
        $settings->sequence_digits = 0;
        $settings->save();

        $number = app(InvoiceNumberAllocator::class)->allocate();

        $this->assertSame('STEM/INV/'.now()->year.'/1', $number);
    }
}
