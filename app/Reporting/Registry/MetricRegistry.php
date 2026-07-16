<?php

declare(strict_types=1);

namespace App\Reporting\Registry;

use App\Reporting\Contracts\MetricRegistryInterface;
use App\Reporting\DTOs\MetricDefinition;
use App\Reporting\Enums\MetricUnit;
use App\Reporting\Enums\ReportDataFreshness;
use App\Reporting\Enums\ZeroDenominatorPolicy;
use App\Reporting\Exceptions\DuplicateMetricKeyException;
use App\Reporting\Support\UniqueDefinitionKeys;

/**
 * The Version 1 metric catalogue (Phase 18B §11) — metadata only, so
 * two future dashboards can never define the same named KPI two
 * different ways. Per §11, this registers metadata for the metrics
 * needed by the next operations-reporting phase without necessarily
 * implementing their queries yet; `calculationOwner` says explicitly,
 * per metric, whether a query exists.
 *
 * Two gaps were found during discovery and are called out rather than
 * silently worked around: no `RecurrenceFrequency`-backed grouping
 * survives on an individual `Booking` row after `WizardBookingService
 * ::bookRecurring()` creates each occurrence as an independent row (no
 * `recurrence_group_id`/series column exists anywhere), so the
 * single/daily/weekly split has no authoritative per-row data source
 * yet — a later phase must decide whether to add one. "Rescheduled
 * bookings" (also requested by the SRS) has no authoritative source at
 * all (no reschedule-count column, no dedicated audit event found) and
 * is therefore not registered at all, per the explicit "only if current
 * source data supports an authoritative definition" instruction.
 */
final class MetricRegistry implements MetricRegistryInterface
{
    /** @var array<string, MetricDefinition> */
    private array $definitions;

    public function __construct()
    {
        $this->definitions = $this->buildDefinitions();
    }

    public function all(): array
    {
        return array_values($this->definitions);
    }

    public function find(string $key): ?MetricDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    /** @return array<string, MetricDefinition> */
    private function buildDefinitions(): array
    {
        return UniqueDefinitionKeys::index(
            $this->definitionList(),
            fn (MetricDefinition $definition): string => $definition->key,
            fn (string $key) => throw DuplicateMetricKeyException::forKey($key),
        );
    }

    /** @return list<MetricDefinition> */
    private function definitionList(): array
    {
        return [
            new MetricDefinition(
                key: 'bookings_scheduled',
                label: 'Bookings scheduled',
                description: 'Every booking created in the period, regardless of eventual outcome.',
                sourceDomain: 'Booking',
                timestampField: 'created_at',
                includedStatuses: [],
                excludedStatuses: [],
                unit: MetricUnit::Count,
                sensitive: false,
                financial: false,
                requiredPermission: 'ViewBookingLessonReports',
                supportedDimensions: ['country', 'subject', 'booking_type'],
                zeroDenominatorPolicy: ZeroDenominatorPolicy::ReturnZero,
                timezoneBehavior: 'Uses the report\'s reporting timezone.',
                freshness: ReportDataFreshness::Live,
                calculationOwner: 'Not yet implemented (Phase 18C, planned).',
            ),
            new MetricDefinition(
                key: 'demo_bookings',
                label: 'Demo bookings',
                description: 'Bookings of the approved Version 1 "free_demo" booking type.',
                sourceDomain: 'Booking',
                timestampField: 'created_at',
                includedStatuses: ['free_demo'],
                excludedStatuses: ['paid_one_to_one'],
                unit: MetricUnit::Count,
                sensitive: false,
                financial: false,
                requiredPermission: 'ViewBookingLessonReports',
                supportedDimensions: ['country', 'subject'],
                zeroDenominatorPolicy: ZeroDenominatorPolicy::ReturnZero,
                timezoneBehavior: 'Uses the report\'s reporting timezone.',
                freshness: ReportDataFreshness::Live,
                calculationOwner: 'Not yet implemented (Phase 18C, planned). BookingAnalyticsService already computes a related "demo requests/bookers" figure — reconcile rather than duplicate when implemented.',
            ),
            new MetricDefinition(
                key: 'paid_bookings',
                label: 'Paid bookings',
                description: 'Bookings of the approved Version 1 "paid_one_to_one" booking type.',
                sourceDomain: 'Booking',
                timestampField: 'created_at',
                includedStatuses: ['paid_one_to_one'],
                excludedStatuses: ['free_demo'],
                unit: MetricUnit::Count,
                sensitive: false,
                financial: false,
                requiredPermission: 'ViewBookingLessonReports',
                supportedDimensions: ['country', 'subject'],
                zeroDenominatorPolicy: ZeroDenominatorPolicy::ReturnZero,
                timezoneBehavior: 'Uses the report\'s reporting timezone.',
                freshness: ReportDataFreshness::Live,
                calculationOwner: 'Not yet implemented (Phase 18C, planned).',
            ),
            new MetricDefinition(
                key: 'single_paid_bookings',
                label: 'Single paid bookings',
                description: 'Paid bookings that are not part of a recurring series.',
                sourceDomain: 'Booking',
                timestampField: 'created_at',
                includedStatuses: ['paid_one_to_one'],
                excludedStatuses: [],
                unit: MetricUnit::Count,
                sensitive: false,
                financial: false,
                requiredPermission: 'ViewBookingLessonReports',
                supportedDimensions: ['country', 'subject'],
                zeroDenominatorPolicy: ZeroDenominatorPolicy::ReturnZero,
                timezoneBehavior: 'Uses the report\'s reporting timezone.',
                freshness: ReportDataFreshness::Live,
                calculationOwner: 'GAP — no `recurrence_group_id`/series column exists on `bookings`; each recurring occurrence is created as an independent row with nothing distinguishing it from a single booking after creation. Not implementable until a later phase adds a durable recurrence-membership column.',
            ),
            new MetricDefinition(
                key: 'daily_recurring_bookings',
                label: 'Daily recurring bookings',
                description: 'Paid bookings belonging to a daily recurrence series.',
                sourceDomain: 'Booking',
                timestampField: 'created_at',
                includedStatuses: ['paid_one_to_one', 'daily'],
                excludedStatuses: [],
                unit: MetricUnit::Count,
                sensitive: false,
                financial: false,
                requiredPermission: 'ViewBookingLessonReports',
                supportedDimensions: ['country', 'subject'],
                zeroDenominatorPolicy: ZeroDenominatorPolicy::ReturnZero,
                timezoneBehavior: 'Uses the report\'s reporting timezone.',
                freshness: ReportDataFreshness::Live,
                calculationOwner: 'GAP — same recurrence-membership gap as `single_paid_bookings`.',
            ),
            new MetricDefinition(
                key: 'weekly_recurring_bookings',
                label: 'Weekly recurring bookings',
                description: 'Paid bookings belonging to a weekly recurrence series.',
                sourceDomain: 'Booking',
                timestampField: 'created_at',
                includedStatuses: ['paid_one_to_one', 'weekly'],
                excludedStatuses: [],
                unit: MetricUnit::Count,
                sensitive: false,
                financial: false,
                requiredPermission: 'ViewBookingLessonReports',
                supportedDimensions: ['country', 'subject'],
                zeroDenominatorPolicy: ZeroDenominatorPolicy::ReturnZero,
                timezoneBehavior: 'Uses the report\'s reporting timezone.',
                freshness: ReportDataFreshness::Live,
                calculationOwner: 'GAP — same recurrence-membership gap as `single_paid_bookings`.',
            ),
            new MetricDefinition(
                key: 'lessons_completed',
                label: 'Lessons completed',
                description: 'Lessons whose finalized outcome is Completed.',
                sourceDomain: 'Lessons',
                timestampField: 'outcome_finalized_at',
                includedStatuses: ['completed'],
                excludedStatuses: ['pending', 'student_no_show', 'instructor_no_show', 'both_absent', 'technical_issue', 'cancelled'],
                unit: MetricUnit::Count,
                sensitive: false,
                financial: false,
                requiredPermission: 'ViewBookingLessonReports',
                supportedDimensions: ['country', 'subject', 'instructor'],
                zeroDenominatorPolicy: ZeroDenominatorPolicy::ReturnZero,
                timezoneBehavior: 'Uses the report\'s reporting timezone.',
                freshness: ReportDataFreshness::Live,
                calculationOwner: 'Not yet implemented (Phase 18C, planned). Authoritative source: `LessonOutcome::Completed` — never `LessonStatus::Completed` alone, which precedes finalization.',
            ),
            new MetricDefinition(
                key: 'student_no_shows',
                label: 'Student no-shows',
                description: 'Lessons whose finalized outcome is Student No-Show.',
                sourceDomain: 'Lessons',
                timestampField: 'outcome_finalized_at',
                includedStatuses: ['student_no_show'],
                excludedStatuses: ['completed', 'instructor_no_show', 'both_absent', 'technical_issue', 'cancelled', 'pending'],
                unit: MetricUnit::Count,
                sensitive: false,
                financial: false,
                requiredPermission: 'ViewOperationalReports',
                supportedDimensions: ['country', 'instructor'],
                zeroDenominatorPolicy: ZeroDenominatorPolicy::ReturnZero,
                timezoneBehavior: 'Uses the report\'s reporting timezone.',
                freshness: ReportDataFreshness::Live,
                calculationOwner: 'Not yet implemented (Phase 18C, planned).',
            ),
            new MetricDefinition(
                key: 'instructor_no_shows',
                label: 'Instructor no-shows',
                description: 'Lessons whose finalized outcome is Instructor No-Show.',
                sourceDomain: 'Lessons',
                timestampField: 'outcome_finalized_at',
                includedStatuses: ['instructor_no_show'],
                excludedStatuses: ['completed', 'student_no_show', 'both_absent', 'technical_issue', 'cancelled', 'pending'],
                unit: MetricUnit::Count,
                sensitive: false,
                financial: false,
                requiredPermission: 'ViewOperationalReports',
                supportedDimensions: ['country', 'instructor'],
                zeroDenominatorPolicy: ZeroDenominatorPolicy::ReturnZero,
                timezoneBehavior: 'Uses the report\'s reporting timezone.',
                freshness: ReportDataFreshness::Live,
                calculationOwner: 'Not yet implemented (Phase 18C, planned). Also feeds `App\Quality`\'s existing InstructorNoShow/RepeatedInstructorNoShows detectors — reuse, never recompute independently.',
            ),
            new MetricDefinition(
                key: 'technical_issue_lessons',
                label: 'Technical-issue lessons',
                description: 'Lessons whose finalized outcome is Technical Issue.',
                sourceDomain: 'Lessons',
                timestampField: 'outcome_finalized_at',
                includedStatuses: ['technical_issue'],
                excludedStatuses: ['completed', 'student_no_show', 'instructor_no_show', 'both_absent', 'cancelled', 'pending'],
                unit: MetricUnit::Count,
                sensitive: false,
                financial: false,
                requiredPermission: 'ViewOperationalReports',
                supportedDimensions: ['country', 'instructor'],
                zeroDenominatorPolicy: ZeroDenominatorPolicy::ReturnZero,
                timezoneBehavior: 'Uses the report\'s reporting timezone.',
                freshness: ReportDataFreshness::Live,
                calculationOwner: 'Not yet implemented (Phase 18C, planned).',
            ),
            new MetricDefinition(
                key: 'cancelled_bookings',
                label: 'Cancelled bookings',
                description: 'Bookings cancelled in the period.',
                sourceDomain: 'Booking',
                timestampField: 'cancelled_at',
                includedStatuses: ['cancelled'],
                excludedStatuses: ['pending', 'confirmed', 'completed', 'no_show'],
                unit: MetricUnit::Count,
                sensitive: false,
                financial: false,
                requiredPermission: 'ViewOperationalReports',
                supportedDimensions: ['country', 'subject'],
                zeroDenominatorPolicy: ZeroDenominatorPolicy::ReturnZero,
                timezoneBehavior: 'Uses the report\'s reporting timezone.',
                freshness: ReportDataFreshness::Live,
                calculationOwner: 'Not yet implemented (Phase 18C, planned). BookingAnalyticsService already computes a related "cancelled/cancellation rate" figure — reconcile rather than duplicate when implemented.',
            ),
            new MetricDefinition(
                key: 'meeting_creation_failures',
                label: 'Meeting creation failures',
                description: 'Bookings whose meeting could not be created (bookings.meeting_status = Failed).',
                sourceDomain: 'Booking (Meetings)',
                timestampField: 'updated_at',
                includedStatuses: ['failed'],
                excludedStatuses: ['pending', 'created', 'cancelled'],
                unit: MetricUnit::Count,
                sensitive: false,
                financial: false,
                requiredPermission: 'ViewMeetingReports',
                supportedDimensions: ['instructor'],
                zeroDenominatorPolicy: ZeroDenominatorPolicy::ReturnZero,
                timezoneBehavior: 'Uses the report\'s reporting timezone.',
                freshness: ReportDataFreshness::Live,
                calculationOwner: 'Not yet implemented (Phase 18C, planned). Authoritative source: `MeetingStatus::Failed` on `bookings.meeting_status`.',
            ),
        ];
    }
}
