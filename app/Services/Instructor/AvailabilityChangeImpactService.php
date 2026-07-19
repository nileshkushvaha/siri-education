<?php

declare(strict_types=1);

namespace App\Services\Instructor;

use App\Booking\DTOs\AvailabilityChangeImpact;
use App\Booking\Enums\BookingStatus;
use App\Booking\Repositories\AvailabilityRepository;
use App\Models\Booking;
use App\Models\TeacherAvailability;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Phase 24I — GAP-019/SRS-10-12/SRS §10.24: analyzes a PROPOSED
 * availability mutation against the instructor's future confirmed
 * bookings, without mutating anything. A booking is "affected" when it
 * is this instructor's, starts in the future, is in Confirmed status
 * (the SRS's "confirmed bookings" — Pending payment reservations,
 * cancelled, completed, and no-show bookings are excluded), was covered
 * by the effective weekly schedule before the proposed change, and
 * would NOT be covered after it. Coverage before/after both run through
 * AvailabilityRepository::rowsCover() — the one existing
 * timezone/DST/midnight-aware calculation, never a duplicate.
 *
 * The fingerprint binds instructor + mutation type + normalized
 * proposal + the identity/version of every affected booking + the
 * schedule version, HMAC'd with the app key: any new/cancelled booking,
 * any other window change, or any edit to the proposed values yields a
 * different fingerprint, so a stale acknowledgment can never authorize
 * a materially different change. The token is opaque — it carries no
 * student data.
 */
final class AvailabilityChangeImpactService
{
    public function __construct(private readonly AvailabilityRepository $availability) {}

    /**
     * @param  Collection<int, TeacherAvailability>  $currentRows  the instructor's current active windows (teacher relation loaded)
     * @param  Collection<int, TeacherAvailability>  $proposedRows  the hypothetical after-state (may contain unsaved clones)
     * @param  array<string, mixed>  $proposal  normalized mutation payload for fingerprinting
     */
    public function analyzeWindowChange(User $teacher, Collection $currentRows, Collection $proposedRows, string $mutationType, array $proposal): AvailabilityChangeImpact
    {
        $fallbackTimezone = $teacher->profile?->timezone;

        $affected = $this->futureConfirmedBookings($teacher->id)
            ->filter(fn (Booking $booking): bool => $this->availability->rowsCover($currentRows, $booking->starts_at, $booking->ends_at, $fallbackTimezone)
                && ! $this->availability->rowsCover($proposedRows, $booking->starts_at, $booking->ends_at, $fallbackTimezone))
            ->values();

        return $this->buildImpact($teacher, $affected, $mutationType, $proposal);
    }

    /**
     * Time off overrides weekly availability outright, so impact is a
     * plain interval overlap: future confirmed bookings inside the
     * PROPOSED unavailable interval that were not already inside the
     * previous one (extending time off warns only about the newly
     * blocked region; shrinking or deleting it warns about nothing).
     *
     * @param  array<string, mixed>  $proposal
     */
    public function analyzeTimeOffChange(
        User $teacher,
        CarbonImmutable $proposedStartsAt,
        CarbonImmutable $proposedEndsAt,
        ?CarbonImmutable $previousStartsAt,
        ?CarbonImmutable $previousEndsAt,
        string $mutationType,
        array $proposal,
    ): AvailabilityChangeImpact {
        $affected = $this->futureConfirmedBookings($teacher->id)
            ->filter(function (Booking $booking) use ($proposedStartsAt, $proposedEndsAt, $previousStartsAt, $previousEndsAt): bool {
                $inProposed = $booking->starts_at->lessThan($proposedEndsAt) && $booking->ends_at->greaterThan($proposedStartsAt);

                if (! $inProposed) {
                    return false;
                }

                $inPrevious = $previousStartsAt !== null
                    && $previousEndsAt !== null
                    && $booking->starts_at->lessThan($previousEndsAt)
                    && $booking->ends_at->greaterThan($previousStartsAt);

                return ! $inPrevious;
            })
            ->values();

        return $this->buildImpact($teacher, $affected, $mutationType, $proposal);
    }

    /**
     * Informational count for the vacation-mode confirmation panel:
     * enabling vacation blocks NEW bookings only — it never removes
     * windows or strands existing lessons, so under the coverage
     * definition nothing is "affected" — but the instructor should see
     * how many confirmed lessons they still owe while on vacation.
     */
    public function upcomingConfirmedCount(User $teacher): int
    {
        return Booking::query()
            ->forInstructor($teacher->id)
            ->where('status', BookingStatus::Confirmed)
            ->where('starts_at', '>', now())
            ->count();
    }

    /** Recomputes the fingerprint for the caller's proposal so a submitted acknowledgment can be compared. */
    public function fingerprintMatches(AvailabilityChangeImpact $impact, ?string $token): bool
    {
        return $token !== null && $impact->fingerprint !== '' && hash_equals($impact->fingerprint, $token);
    }

    /** @return Collection<int, Booking> bounded: future + Confirmed + this instructor, minimal columns */
    private function futureConfirmedBookings(int $teacherId): Collection
    {
        return Booking::query()
            ->forInstructor($teacherId)
            ->where('status', BookingStatus::Confirmed)
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->get(['id', 'reference', 'starts_at', 'ends_at', 'updated_at']);
    }

    /**
     * @param  Collection<int, Booking>  $affected
     * @param  array<string, mixed>  $proposal
     */
    private function buildImpact(User $teacher, Collection $affected, string $mutationType, array $proposal): AvailabilityChangeImpact
    {
        if ($affected->isEmpty()) {
            return AvailabilityChangeImpact::none($mutationType);
        }

        $timezone = $teacher->profile?->timezone ?: config('app.timezone', 'UTC');

        $summaries = $affected
            ->take(AvailabilityChangeImpact::SUMMARY_LIMIT)
            ->map(fn (Booking $booking): array => [
                'reference' => $booking->reference,
                'starts_at' => $booking->starts_at->setTimezone($timezone)->format('D, M j Y \a\t g:i A T'),
            ])
            ->all();

        return new AvailabilityChangeImpact(
            requiresConfirmation: true,
            affectedCount: $affected->count(),
            affectedBookingIds: $affected->pluck('id')->all(),
            affectedSummaries: $summaries,
            earliestAffectedStartsAt: CarbonImmutable::instance($affected->first()->starts_at),
            latestAffectedStartsAt: CarbonImmutable::instance($affected->last()->starts_at),
            mutationType: $mutationType,
            fingerprint: $this->fingerprint($teacher->id, $mutationType, $proposal, $affected),
            analyzedAt: CarbonImmutable::now(),
        );
    }

    /** @param Collection<int, Booking> $affected */
    private function fingerprint(int $teacherId, string $mutationType, array $proposal, Collection $affected): string
    {
        ksort($proposal);

        $scheduleVersion = TeacherAvailability::query()
            ->forTeacher($teacherId)
            ->selectRaw('COUNT(*) AS row_count, COALESCE(MAX(updated_at), "") AS latest')
            ->first();

        $canonical = json_encode([
            'teacher_id' => $teacherId,
            'mutation' => $mutationType,
            'proposal' => $proposal,
            'affected' => $affected->map(fn (Booking $booking): array => [
                'id' => $booking->id,
                'version' => $booking->updated_at?->toIso8601String(),
            ])->all(),
            'schedule_version' => [$scheduleVersion->row_count ?? 0, (string) ($scheduleVersion->latest ?? '')],
        ], JSON_THROW_ON_ERROR);

        return hash_hmac('sha256', $canonical, (string) config('app.key'));
    }
}
