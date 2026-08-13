<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use App\Booking\Enums\BookingLocationType;
use App\Booking\Enums\RecurrenceFrequency;
use Carbon\CarbonImmutable;

/**
 * Immutable input for requesting a booking. Built by FormRequests (HTTP)
 * or callers such as console commands — never inside Services. Every
 * booking has an authenticated student — no unauthenticated guest
 * booking concept exists anywhere in this domain.
 */
final readonly class CreateBookingData
{
    public string $typeKey;

    public int $studentId;

    public int $instructorId;

    /** Always normalized to UTC (see constructor) — the sole canonical instant every downstream write/comparison relies on. */
    public CarbonImmutable $startsAt;

    public int $durationMinutes;

    public BookingLocationType $locationType;

    public string $timezone;

    public ?string $notes;

    /** @var array<string, mixed> */
    public array $meta;

    public ?RecurrenceFrequency $recurrenceFrequency;

    public ?BookingAcademicContextData $academicContext;

    /**
     * @param  array<string, mixed>  $meta  type-specific payload (subject, grade, recurring_group, …)
     * @param  RecurrenceFrequency|null  $recurrenceFrequency  Data-provenance field — set only by
     *                                                         the recurring-booking creation path (never inferred later), null for every single/non-recurring
     *                                                         booking. This is the sole authoritative source for reporting's single/daily/weekly classification.
     * @param  BookingAcademicContextData|null  $academicContext  Phase 3 — the fully-resolved,
     *                                                            already-validated academic snapshot for a country-aware Free Demo booking. Null for every
     *                                                            legacy/paid booking. CreateBookingAction persists this atomically alongside the Booking row
     *                                                            (BookingAcademicContext) when present — never asynchronously, never via a queued listener.
     */
    public function __construct(
        string $typeKey,
        int $studentId,
        int $instructorId,
        CarbonImmutable $startsAt,
        int $durationMinutes,
        BookingLocationType $locationType = BookingLocationType::Online,
        string $timezone = 'UTC',
        ?string $notes = null,
        array $meta = [],
        ?RecurrenceFrequency $recurrenceFrequency = null,
        ?BookingAcademicContextData $academicContext = null,
    ) {
        $this->typeKey = $typeKey;
        $this->studentId = $studentId;
        $this->instructorId = $instructorId;
        // §25/§30: the caller-supplied instant may carry any display
        // timezone (e.g. the student's, per the country-aware Demo
        // flow) — normalized to UTC here, once, at the domain boundary,
        // so every downstream write/comparison (BookingRepository::create(),
        // duplicateExists(), …) is guaranteed to persist/compare the
        // exact same instant the student selected, never a
        // misinterpreted wall-clock reading of a non-UTC Carbon
        // instance (Eloquent's datetime cast does not itself convert to
        // UTC on write — it formats the Carbon instance's OWN
        // timezone's digits).
        $this->startsAt = $startsAt->utc();
        $this->durationMinutes = $durationMinutes;
        $this->locationType = $locationType;
        $this->timezone = $timezone;
        $this->notes = $notes;
        $this->meta = $meta;
        $this->recurrenceFrequency = $recurrenceFrequency;
        $this->academicContext = $academicContext;
    }

    public function endsAt(): CarbonImmutable
    {
        return $this->startsAt->addMinutes($this->durationMinutes);
    }
}
