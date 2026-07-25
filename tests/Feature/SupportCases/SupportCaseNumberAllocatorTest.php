<?php

declare(strict_types=1);

namespace Tests\Feature\SupportCases;

use App\Models\SupportCaseNumberSequence;
use App\Settings\SupportCaseSettings;
use App\SupportCases\Services\SupportCaseNumberAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/** SRS §25.12 — mirrors InvoiceNumberSequenceTest's coverage shape. */
class SupportCaseNumberAllocatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_format_produces_sup_year_sequence(): void
    {
        $number = app(SupportCaseNumberAllocator::class)->allocate();

        $this->assertSame('SUP-'.now()->year.'-000001', $number);
    }

    public function test_sequential_numbers_never_repeat(): void
    {
        $allocator = app(SupportCaseNumberAllocator::class);

        $first = $allocator->allocate();
        $second = $allocator->allocate();
        $third = $allocator->allocate();

        $this->assertSame([$first, $second, $third], array_unique([$first, $second, $third]));
        $this->assertStringEndsWith('000001', $first);
        $this->assertStringEndsWith('000002', $second);
        $this->assertStringEndsWith('000003', $third);
    }

    public function test_scope_is_annual(): void
    {
        app(SupportCaseNumberAllocator::class)->allocate();

        $this->assertSame(1, SupportCaseNumberSequence::query()->count());
        $this->assertSame((string) now()->year, SupportCaseNumberSequence::query()->sole()->scope_key);
    }

    public function test_prefix_and_digits_are_configurable(): void
    {
        $settings = app(SupportCaseSettings::class);
        $settings->number_prefix = 'HELP';
        $settings->sequence_digits = 3;
        $settings->save();

        $number = app(SupportCaseNumberAllocator::class)->allocate();

        $this->assertSame('HELP-'.now()->year.'-001', $number);
    }

    public function test_missing_sequence_token_throws(): void
    {
        $settings = app(SupportCaseSettings::class);
        $settings->number_format = '{prefix}/{year}';
        $settings->save();

        $this->expectException(RuntimeException::class);
        app(SupportCaseNumberAllocator::class)->allocate();
    }
}
