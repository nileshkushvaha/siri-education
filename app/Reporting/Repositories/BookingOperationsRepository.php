<?php

declare(strict_types=1);

namespace App\Reporting\Repositories;

use App\Booking\Enums\BookingActivityAction;
use App\Models\Booking;
use App\Models\BookingActivity;
use App\Reporting\DTOs\Operations\BookingOperationsSummaryData;
use App\Reporting\DTOs\Operations\LabeledCountRow;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\Support\RecurrenceClassifier;
use App\Reporting\ValueObjects\ReportingPeriod;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only aggregate queries over `bookings` (Phase 18C). Date basis:
 * `created_at` throughout (business-event view — "bookings created in
 * the period") except `rescheduledCount()`, which counts
 * `booking_activities` rows (a reschedule can occur well after the
 * booking's own creation). Every method uses database aggregation —
 * never a full collection fetch — and every breakdown is bounded
 * (`LIMIT 10`).
 */
final class BookingOperationsRepository
{
    public function summary(ReportingPeriod $period, ReportFilters $filters): BookingOperationsSummaryData
    {
        $base = $this->scoped($period, $filters);

        return new BookingOperationsSummaryData(
            total: (clone $base)->count(),
            byType: $this->countByType($period, $filters),
            byStatus: $this->countByStatus($period, $filters),
            byRecurrence: $this->countByRecurrence($period, $filters),
            rescheduled: $this->rescheduledCount($period, $filters),
        );
    }

    /** @return array<string, int> keyed by ReportingBookingType::value */
    public function countByType(ReportingPeriod $period, ReportFilters $filters): array
    {
        return $this->scoped($period, $filters)
            ->join('booking_types', 'booking_types.id', '=', 'bookings.booking_type_id')
            ->selectRaw('booking_types.key as type_key, count(*) as aggregate')
            ->groupBy('booking_types.key')
            ->pluck('aggregate', 'type_key')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** @return array<string, int> keyed by BookingStatus::value */
    public function countByStatus(ReportingPeriod $period, ReportFilters $filters): array
    {
        return $this->scoped($period, $filters)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->mapWithKeys(fn ($v, $k) => [(is_string($k) ? $k : $k->value) => (int) $v])
            ->all();
    }

    /** @return array<string, int> keyed by RecurrenceClassifier bucket ('single'|'daily'|'weekly'|'unknown_historical') */
    public function countByRecurrence(ReportingPeriod $period, ReportFilters $filters): array
    {
        $rows = $this->scoped($period, $filters)
            ->selectRaw(RecurrenceClassifier::caseExpression().' as bucket, count(*) as aggregate')
            ->groupBy('bucket')
            ->pluck('aggregate', 'bucket');

        $result = array_fill_keys(RecurrenceClassifier::buckets(), 0);

        foreach ($rows as $bucket => $count) {
            $result[$bucket] = (int) $count;
        }

        return $result;
    }

    /**
     * Reschedule count from `booking_activities` (structured, enum-typed
     * `action` column — never audit-message text parsing). Date basis:
     * the activity's own `created_at`, not the booking's.
     */
    public function rescheduledCount(ReportingPeriod $period, ReportFilters $filters): int
    {
        $query = BookingActivity::query()
            ->where('action', BookingActivityAction::Rescheduled)
            ->where('created_at', '>=', $period->startUtc)
            ->where('created_at', '<', $period->endUtcExclusive);

        if ($filters->instructorId !== null || $filters->countryId !== null || $filters->bookingType !== null) {
            $query->whereHas('booking', fn (Builder $q) => $this->applyBookingScopedFilters($q, $filters));
        }

        return $query->count();
    }

    /** @return list<LabeledCountRow> top 10 subjects by booking count (via the associated Lesson — Booking itself has no subject FK, only a free-text meta snapshot). */
    public function bySubject(ReportingPeriod $period, ReportFilters $filters, int $limit = 10): array
    {
        return $this->scoped($period, $filters)
            ->join('lessons', 'lessons.booking_id', '=', 'bookings.id')
            ->join('subjects', 'subjects.id', '=', 'lessons.subject_id')
            ->selectRaw('subjects.name as label, count(*) as aggregate')
            ->groupBy('subjects.name')
            ->orderByDesc('aggregate')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => new LabeledCountRow((string) $row->label, (int) $row->aggregate))
            ->all();
    }

    /** @return list<LabeledCountRow> top 10 instructors by booking count. */
    public function byInstructor(ReportingPeriod $period, ReportFilters $filters, int $limit = 10): array
    {
        return $this->scoped($period, $filters)
            ->join('users', 'users.id', '=', 'bookings.instructor_id')
            ->selectRaw('users.first_name, users.last_name, count(*) as aggregate')
            ->groupBy('users.id', 'users.first_name', 'users.last_name')
            ->orderByDesc('aggregate')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => new LabeledCountRow(trim("{$row->first_name} {$row->last_name}"), (int) $row->aggregate))
            ->all();
    }

    /** @return list<LabeledCountRow> top 10 countries by booking count (student's registered country). */
    public function byCountry(ReportingPeriod $period, ReportFilters $filters, int $limit = 10): array
    {
        return $this->scoped($period, $filters)
            ->join('users', 'users.id', '=', 'bookings.student_id')
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->join('countries', 'countries.id', '=', 'user_profiles.country_id')
            ->selectRaw('countries.name as label, count(*) as aggregate')
            ->groupBy('countries.name')
            ->orderByDesc('aggregate')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => new LabeledCountRow((string) $row->label, (int) $row->aggregate))
            ->all();
    }

    /** @return list<LabeledCountRow> booking counts grouped by the booking type's fixed duration (each Version 1 type has one duration, so this doubles as a duration breakdown). */
    public function byDuration(ReportingPeriod $period, ReportFilters $filters): array
    {
        return $this->scoped($period, $filters)
            ->join('booking_types', 'booking_types.id', '=', 'bookings.booking_type_id')
            ->selectRaw('booking_types.duration_minutes as duration, count(*) as aggregate')
            ->groupBy('booking_types.duration_minutes')
            ->orderBy('duration')
            ->get()
            ->map(fn ($row) => new LabeledCountRow("{$row->duration} min", (int) $row->aggregate))
            ->all();
    }

    private function scoped(ReportingPeriod $period, ReportFilters $filters): Builder
    {
        $query = Booking::query()
            ->where('bookings.created_at', '>=', $period->startUtc)
            ->where('bookings.created_at', '<', $period->endUtcExclusive);

        $this->applyBookingScopedFilters($query, $filters);

        return $query;
    }

    private function applyBookingScopedFilters(Builder $query, ReportFilters $filters): Builder
    {
        if ($filters->instructorId !== null) {
            $query->where('bookings.instructor_id', $filters->instructorId);
        }

        if ($filters->studentId !== null) {
            $query->where('bookings.student_id', $filters->studentId);
        }

        if ($filters->bookingStatus !== null) {
            $query->where('bookings.status', $filters->bookingStatus);
        }

        if ($filters->bookingType !== null) {
            $query->whereHas('type', fn (Builder $q) => $q->where('key', $filters->bookingType->value));
        }

        if ($filters->countryId !== null) {
            $query->whereHas(
                'student',
                fn (Builder $q) => $q->whereHas('profile', fn (Builder $p) => $p->where('country_id', $filters->countryId)),
            );
        }

        if ($filters->subjectId !== null) {
            $query->whereHas('lesson', fn (Builder $q) => $q->where('subject_id', $filters->subjectId));
        }

        return $query;
    }
}
