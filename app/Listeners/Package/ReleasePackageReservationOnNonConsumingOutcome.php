<?php

declare(strict_types=1);

namespace App\Listeners\Package;

use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Events\LessonOutcomeFinalized;
use App\Package\Services\PackageEntitlementService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Phase 4D — releases the committed unit when a package-funded lesson
 * reaches a final outcome that does NOT consume it (spec §25).
 *
 * Phase 4C established which outcomes consume: only Completed does.
 * The no-shows, technical issues and cancellations deliberately do not,
 * and this phase does not revisit that commercial policy — it only
 * makes the RESERVATION agree with the decision Phase 4C already made.
 * Without this, a student's no-show would leave a unit committed to a
 * lesson that can never consume it, quietly shrinking their bookable
 * balance forever.
 *
 * LessonOutcomeFinalized is the right hook because it fires exactly
 * once per lesson, for every finalized outcome, from inside the
 * finalizing transaction (released on commit). Completed is filtered
 * out here so this can never race the consumption path: for a completed
 * lesson the unit is claimed by
 * ConsumePackageEntitlementOnLessonCompleted, atomically, alongside the
 * consumption ledger row. Even if both were somehow delivered, the two
 * are mutually exclusive by construction — releasing requires a
 * still-Reserved reservation, and consumption transitions it under the
 * entitlement's lock.
 *
 * The reservation is released, not deleted: a no-show's freed unit stays
 * auditable (spec §28).
 */
final class ReleasePackageReservationOnNonConsumingOutcome implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly PackageEntitlementService $entitlements,
    ) {}

    public function handle(LessonOutcomeFinalized $event): void
    {
        if ($event->lesson->package_entitlement_id === null) {
            return;
        }

        // Completed is the ONLY consuming outcome — leave it entirely to
        // the consumption path, which claims the same reservation.
        if ($event->outcome === LessonOutcome::Completed) {
            return;
        }

        $booking = $event->lesson->booking;

        if ($booking === null) {
            return;
        }

        $this->entitlements->releaseForBooking($booking, sprintf('lesson_outcome_%s', $event->outcome->value));
    }
}
