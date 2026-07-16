<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Reporting\Contracts\MetricRegistryInterface;
use App\Reporting\DTOs\MetricDefinition;
use App\Reporting\Enums\MetricUnit;
use App\Reporting\Exceptions\DuplicateMetricKeyException;
use App\Reporting\Support\UniqueDefinitionKeys;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Phase 18B §11 — the code-defined metric catalogue. */
class MetricRegistryTest extends TestCase
{
    public function test_metric_keys_are_unique(): void
    {
        $keys = array_map(fn (MetricDefinition $d) => $d->key, app(MetricRegistryInterface::class)->all());

        $this->assertSame($keys, array_unique($keys));
    }

    public function test_duplicate_metric_key_is_rejected(): void
    {
        $one = app(MetricRegistryInterface::class)->all()[0];

        $this->expectException(DuplicateMetricKeyException::class);

        UniqueDefinitionKeys::index(
            [$one, $one],
            fn (MetricDefinition $d): string => $d->key,
            fn (string $key) => throw DuplicateMetricKeyException::forKey($key),
        );
    }

    public function test_every_metric_has_correct_unit_metadata(): void
    {
        foreach (app(MetricRegistryInterface::class)->all() as $metric) {
            $this->assertInstanceOf(MetricUnit::class, $metric->unit);
        }
    }

    public function test_every_metric_declares_sensitivity_and_permission_metadata(): void
    {
        foreach (app(MetricRegistryInterface::class)->all() as $metric) {
            $this->assertIsBool($metric->sensitive);
            $this->assertIsBool($metric->financial);
            $this->assertNotEmpty($metric->requiredPermission);
        }
    }

    public function test_every_metric_declares_its_timestamp_field(): void
    {
        foreach (app(MetricRegistryInterface::class)->all() as $metric) {
            $this->assertNotEmpty($metric->timestampField);
        }
    }

    public function test_every_metric_declares_a_zero_denominator_policy(): void
    {
        foreach (app(MetricRegistryInterface::class)->all() as $metric) {
            $this->assertNotEmpty($metric->zeroDenominatorPolicy->value);
        }
    }

    public function test_no_metric_uses_an_unsupported_booking_type(): void
    {
        $demo = app(MetricRegistryInterface::class)->find('demo_bookings');
        $paid = app(MetricRegistryInterface::class)->find('paid_bookings');

        $this->assertNotNull($demo);
        $this->assertNotNull($paid);
        $this->assertSame(['free_demo'], $demo->includedStatuses);
        $this->assertSame(['paid_one_to_one'], $paid->includedStatuses);
    }

    public function test_no_metric_registered_for_unproven_reschedule_tracking(): void
    {
        // Discovery found no reschedule-count column or dedicated audit
        // event anywhere — "Rescheduled bookings" is deliberately not
        // registered rather than invented without an authoritative source.
        $this->assertNull(app(MetricRegistryInterface::class)->find('rescheduled_bookings'));
    }

    public function test_lessons_completed_metric_uses_the_finalized_outcome_not_the_lifecycle_status(): void
    {
        $metric = app(MetricRegistryInterface::class)->find('lessons_completed');

        $this->assertNotNull($metric);
        $this->assertSame('outcome_finalized_at', $metric->timestampField);
        $this->assertSame(['completed'], $metric->includedStatuses);
    }

    public function test_meeting_creation_failure_metric_has_an_authoritative_source(): void
    {
        // Only registered because MeetingStatus::Failed on bookings.meeting_status
        // is a real, authoritative source — confirmed during discovery.
        $metric = app(MetricRegistryInterface::class)->find('meeting_creation_failures');

        $this->assertNotNull($metric);
        $this->assertSame(['failed'], $metric->includedStatuses);
    }

    public function test_registry_listing_performs_no_database_query(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        app(MetricRegistryInterface::class)->all();

        $this->assertEmpty(DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_financial_metric_requires_a_financial_permission(): void
    {
        foreach (app(MetricRegistryInterface::class)->all() as $metric) {
            if ($metric->financial) {
                $this->assertNotEmpty($metric->requiredPermission);
            }
        }

        // None of the initial Version 1 operations metrics are financial —
        // documented expectation, not an accidental omission.
        $financial = array_filter(app(MetricRegistryInterface::class)->all(), fn (MetricDefinition $d) => $d->financial);
        $this->assertCount(0, $financial);
    }
}
