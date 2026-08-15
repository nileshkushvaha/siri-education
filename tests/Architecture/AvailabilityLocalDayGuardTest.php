<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Booking\Contracts\AvailabilityRepositoryInterface;
use App\Booking\Contracts\BookingRepositoryInterface;
use App\Support\Timezone\LocalDay;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * TZ-2A permanent guard: instructor-local-day rules must never be
 * re-derived from a UTC instant.
 *
 * The two defects this closes (TZ-AUD-005 holiday exclusion, TZ-AUD-006
 * daily booking cap) were both one expression long:
 *
 *     $utcInstant->toDateString()      // "which day is this in UTC?"
 *
 * used where the question was "which day is this for the instructor?".
 * The answers differ for most of every day in most of the world, so the
 * bug was invisible in any fixture where the two happened to coincide —
 * which is why a structural guard is worth more here than another test
 * case.
 *
 * Deliberately NARROW. It inspects only the classes that own instructor
 * availability, and it does not ban `whereDate` repo-wide: date-only
 * columns (`holidays.date`, `teacher_availability.effective_from`) use
 * it correctly and must keep doing so.
 */
class AvailabilityLocalDayGuardTest extends TestCase
{
    /** The classes that decide when an instructor is bookable. */
    private const array AVAILABILITY_CLASSES = [
        'app/Booking/Services/AvailabilityService.php',
        'app/Booking/Repositories/AvailabilityRepository.php',
    ];

    public function test_the_local_day_primitive_exists(): void
    {
        $this->assertTrue(class_exists(LocalDay::class));
    }

    public function test_availability_classes_never_derive_a_calendar_date_from_an_instant(): void
    {
        $offenders = [];

        foreach (self::AVAILABILITY_CLASSES as $relative) {
            $source = $this->strippedSource(base_path($relative));

            // Only INSTANT-valued receivers. Taking `toDateString()` off
            // a booking/slot timestamp asks "which day is this in UTC?"
            // when the question was "which day is this for the
            // instructor?" — LocalDay::containing()/::of() are the
            // supported way to ask.
            //
            // A date-only column is explicitly fine and must stay so:
            // `holidays.date` is cast `immutable_date` and has no
            // timezone semantics to get wrong, so
            // `holidayDatesBetween()` formatting it is correct.
            if (preg_match('/(?:starts_at|ends_at|\$start|\$end|\$startsAt|\$endsAt|\$instant)\s*(?:->\w+\([^)]*\))*->toDateString\s*\(/', $source) === 1) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders, implode(' ', [
            'Instructor availability derived a calendar date straight from an instant.',
            'Use LocalDay::containing($instant, $timezone)->date so the owning calendar is explicit',
            '(TZ-AUD-005/006). Offending files:', implode(', ', $offenders),
        ]));
    }

    public function test_availability_classes_never_filter_bookings_by_a_utc_calendar_day(): void
    {
        $offenders = [];

        foreach (array_merge(self::AVAILABILITY_CLASSES, ['app/Booking/Repositories/BookingRepository.php']) as $relative) {
            $source = $this->strippedSource(base_path($relative));

            // startOfDay()/endOfDay() on a booking timestamp is the
            // other shape of the same mistake: it brackets a UTC day.
            // Local-day boundaries must come from LocalDay, which builds
            // them as local midnights (DST-safe) and converts after.
            if (preg_match('/(?:starts_at|ends_at|\$day|\$date)\s*(?:->\w+\(\))*->(?:startOfDay|endOfDay)\s*\(/', $source) === 1) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders, implode(' ', [
            'A UTC calendar day was used to bracket bookings. Build boundaries with LocalDay,',
            'which anchors on local midnight and stays exact on 23- and 25-hour DST days.',
            'Offending files:', implode(', ', $offenders),
        ]));
    }

    public function test_the_daily_cap_query_can_only_be_called_with_an_explicit_local_day(): void
    {
        // Typing the parameter is what actually prevents the bug coming
        // back: a caller cannot hand over a bare instant and let the
        // repository guess which day it meant.
        $parameter = (new ReflectionClass(BookingRepositoryInterface::class))
            ->getMethod('activeCountForDay')
            ->getParameters()[1];

        $type = $parameter->getType();

        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame(LocalDay::class, $type->getName());
    }

    public function test_the_holiday_check_requires_an_explicit_owning_timezone(): void
    {
        $parameters = (new ReflectionClass(AvailabilityRepositoryInterface::class))
            ->getMethod('isHoliday')
            ->getParameters();

        $this->assertCount(2, $parameters, 'isHoliday() must take the calendar that owns the date');
        $this->assertSame('timezone', $parameters[1]->getName());
    }

    public function test_one_method_owns_the_instructor_calendar_timezone(): void
    {
        // Slot generation and booking enforcement must not each decide
        // separately which calendar an instructor's day belongs to, or
        // an offered slot can be rejected at submit for being on a
        // "different" day.
        $this->assertTrue(
            (new ReflectionClass(AvailabilityRepositoryInterface::class))->hasMethod('calendarTimezoneFor'),
        );

        $service = $this->strippedSource(base_path('app/Booking/Services/AvailabilityService.php'));

        $this->assertSame(
            2,
            substr_count($service, 'calendarTimezoneFor('),
            'Both slots() and ensureAvailable() must resolve the calendar from the same method.',
        );
    }

    /** Executable source only, so comments describing the banned pattern never trip a scan. */
    private function strippedSource(string $file): string
    {
        $kept = '';

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $kept .= $token[1];

                continue;
            }

            $kept .= $token;
        }

        return $kept;
    }
}
