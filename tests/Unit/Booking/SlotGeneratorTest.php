<?php

declare(strict_types=1);

namespace Tests\Unit\Booking;

use App\Booking\Services\SlotGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Tests\TestCase;

class SlotGeneratorTest extends TestCase
{
    private SlotGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new SlotGenerator;
    }

    private function window(string $start, string $end): array
    {
        return [
            'starts_at' => CarbonImmutable::parse($start),
            'ends_at' => CarbonImmutable::parse($end),
        ];
    }

    public function test_slices_window_into_consecutive_slots(): void
    {
        $slots = $this->generator->candidates(
            new Collection([$this->window('2026-08-03 09:00', '2026-08-03 11:00')]),
            durationMinutes: 60,
        );

        $this->assertCount(2, $slots);
        $this->assertSame('09:00', $slots[0]['starts_at']->format('H:i'));
        $this->assertSame('10:00', $slots[0]['ends_at']->format('H:i'));
        $this->assertSame('10:00', $slots[1]['starts_at']->format('H:i'));
    }

    public function test_partial_slot_at_window_end_is_dropped(): void
    {
        $slots = $this->generator->candidates(
            new Collection([$this->window('2026-08-03 09:00', '2026-08-03 10:30')]),
            durationMinutes: 60,
        );

        $this->assertCount(1, $slots);
    }

    public function test_buffer_spaces_consecutive_slots(): void
    {
        $slots = $this->generator->candidates(
            new Collection([$this->window('2026-08-03 09:00', '2026-08-03 12:00')]),
            durationMinutes: 60,
            bufferMinutes: 30,
        );

        // 09:00–10:00, then 10:30–11:30 (10:00 + 30min buffer); 12:00 start no longer fits.
        $this->assertCount(2, $slots);
        $this->assertSame('10:30', $slots[1]['starts_at']->format('H:i'));
    }

    public function test_multiple_windows_are_all_sliced(): void
    {
        $slots = $this->generator->candidates(
            new Collection([
                $this->window('2026-08-03 09:00', '2026-08-03 10:00'),
                $this->window('2026-08-03 14:00', '2026-08-03 16:00'),
            ]),
            durationMinutes: 60,
        );

        $this->assertCount(3, $slots);
    }

    public function test_empty_windows_yield_no_slots(): void
    {
        $this->assertCount(0, $this->generator->candidates(new Collection, 60));
    }

    public function test_conflict_detected_on_overlap(): void
    {
        $busy = new Collection([$this->window('2026-08-03 09:30', '2026-08-03 10:30')]);

        $this->assertTrue($this->generator->conflicts(
            CarbonImmutable::parse('2026-08-03 10:00'),
            CarbonImmutable::parse('2026-08-03 11:00'),
            $busy,
        ));
    }

    public function test_touching_intervals_do_not_conflict(): void
    {
        $busy = new Collection([$this->window('2026-08-03 09:00', '2026-08-03 10:00')]);

        $this->assertFalse($this->generator->conflicts(
            CarbonImmutable::parse('2026-08-03 10:00'),
            CarbonImmutable::parse('2026-08-03 11:00'),
            $busy,
        ));
    }

    public function test_buffer_turns_touching_into_conflict(): void
    {
        $busy = new Collection([$this->window('2026-08-03 09:00', '2026-08-03 10:00')]);

        $this->assertTrue($this->generator->conflicts(
            CarbonImmutable::parse('2026-08-03 10:00'),
            CarbonImmutable::parse('2026-08-03 11:00'),
            $busy,
            bufferMinutes: 15,
        ));
    }

    public function test_no_conflict_when_gap_exceeds_buffer(): void
    {
        $busy = new Collection([$this->window('2026-08-03 09:00', '2026-08-03 10:00')]);

        $this->assertFalse($this->generator->conflicts(
            CarbonImmutable::parse('2026-08-03 10:30'),
            CarbonImmutable::parse('2026-08-03 11:30'),
            $busy,
            bufferMinutes: 15,
        ));
    }

    public function test_timezone_of_inputs_does_not_break_comparison(): void
    {
        // 10:00 UTC == 15:30 Asia/Kolkata — same instant, must conflict.
        $busy = new Collection([$this->window('2026-08-03 09:30:00 UTC', '2026-08-03 10:30:00 UTC')]);

        $this->assertTrue($this->generator->conflicts(
            CarbonImmutable::parse('2026-08-03 15:30:00', 'Asia/Kolkata'),
            CarbonImmutable::parse('2026-08-03 16:30:00', 'Asia/Kolkata'),
            $busy,
        ));
    }
}
