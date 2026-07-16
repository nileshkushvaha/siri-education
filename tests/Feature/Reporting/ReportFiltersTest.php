<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Booking\Enums\BookingStatus;
use App\Reporting\Enums\ReportingBookingType;
use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Enums\ReportingRecurrenceType;
use App\Reporting\Exceptions\UnsupportedReportFilterException;
use App\Reporting\Filters\ReportFilterKey;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\ValueObjects\ReportingPeriod;
use Tests\TestCase;

/** Phase 18B §8 — the shared typed, permission-safe report filter contract. */
class ReportFiltersTest extends TestCase
{
    private function period(): ReportingPeriod
    {
        return ReportingPeriod::forPreset(ReportingPeriodPreset::Last30Days, 'UTC');
    }

    public function test_valid_typed_filters_are_constructible(): void
    {
        $filters = new ReportFilters(
            period: $this->period(),
            countryId: 1,
            bookingType: ReportingBookingType::PaidOneToOne,
            bookingStatus: BookingStatus::Confirmed,
        );

        $this->assertSame(1, $filters->countryId);
        $this->assertSame(ReportingBookingType::PaidOneToOne, $filters->bookingType);
        $this->assertSame(BookingStatus::Confirmed, $filters->bookingStatus);
    }

    public function test_serialization_and_restoration_round_trips(): void
    {
        $original = new ReportFilters(
            period: $this->period(),
            countryId: 7,
            bookingType: ReportingBookingType::FreeDemo,
            bookingStatus: BookingStatus::Cancelled,
        );

        $safe = $original->toSafeArray();
        $restored = ReportFilters::fromSafeArray($this->period(), $safe);

        $this->assertSame(7, $restored->countryId);
        $this->assertSame(ReportingBookingType::FreeDemo, $restored->bookingType);
        $this->assertSame(BookingStatus::Cancelled, $restored->bookingStatus);
    }

    public function test_safe_array_contains_only_scalars_never_a_model(): void
    {
        $filters = new ReportFilters(period: $this->period(), countryId: 3, bookingType: ReportingBookingType::PaidOneToOne);
        $safe = $filters->toSafeArray();

        foreach ($safe['period'] as $value) {
            $this->assertIsScalar($value);
        }

        foreach ($safe as $key => $value) {
            if ($key === 'period') {
                continue;
            }

            $this->assertIsScalar($value, "Filter '{$key}' must serialize to a scalar, never a model.");
        }
    }

    public function test_unknown_enum_value_is_rejected_not_silently_ignored(): void
    {
        $this->expectException(UnsupportedReportFilterException::class);

        ReportFilters::fromSafeArray($this->period(), [
            ReportFilterKey::BookingType->value => 'not_a_real_booking_type',
        ]);
    }

    public function test_only_free_demo_and_paid_one_to_one_booking_types_are_accepted(): void
    {
        $this->assertSame(ReportingBookingType::FreeDemo, ReportingBookingType::tryFrom('free_demo'));
        $this->assertSame(ReportingBookingType::PaidOneToOne, ReportingBookingType::tryFrom('paid_one_to_one'));
        $this->assertNull(ReportingBookingType::tryFrom('group_class'));
    }

    public function test_unknown_booking_type_never_falls_back_to_paid_one_to_one(): void
    {
        $this->assertNull(ReportingBookingType::tryFrom('counselling'));
        $this->assertNull(ReportingBookingType::tryFrom('webinar'));
    }

    public function test_only_single_daily_weekly_recurrence_types_are_accepted(): void
    {
        $cases = array_map(fn ($case) => $case->value, ReportingRecurrenceType::cases());

        $this->assertSame(['single', 'daily', 'weekly'], $cases);
    }

    public function test_restricted_to_nulls_out_unsupported_dimensions(): void
    {
        $filters = new ReportFilters(
            period: $this->period(),
            countryId: 5,
            bookingType: ReportingBookingType::PaidOneToOne,
            bookingStatus: BookingStatus::Confirmed,
        );

        $restricted = $filters->restrictedTo([ReportFilterKey::Country]);

        $this->assertSame(5, $restricted->countryId);
        $this->assertNull($restricted->bookingType);
        $this->assertNull($restricted->bookingStatus);
    }

    public function test_restricted_to_never_broadens_scope(): void
    {
        $filters = new ReportFilters(period: $this->period(), countryId: 5);

        // Asking to restrict to a key the original filters never set stays null — it can never appear.
        $restricted = $filters->restrictedTo([ReportFilterKey::Country, ReportFilterKey::BookingStatus]);

        $this->assertNull($restricted->bookingStatus);
    }

    public function test_archived_entity_id_is_still_a_usable_filter_value(): void
    {
        // Filters carry only a bare id — an archived/soft-deleted student or
        // instructor id is exactly as valid a filter value as an active one;
        // nothing here re-validates "is this id currently active."
        $filters = new ReportFilters(period: $this->period(), studentId: 999999);

        $this->assertSame(999999, $filters->studentId);
    }
}
