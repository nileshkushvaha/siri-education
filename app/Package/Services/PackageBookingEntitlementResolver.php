<?php

declare(strict_types=1);

namespace App\Package\Services;

use App\Booking\DTOs\BookingAcademicContextData;
use App\Models\StudentPackageEntitlement;
use App\Models\StudentPackagePurchase;
use App\Models\User;
use App\Package\Enums\PackagePurchaseStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Phase 4D — answers exactly one question: "which of this student's
 * packages could legitimately fund THIS booking?"
 *
 * What it deliberately does NOT do is choose. It returns every
 * qualifying entitlement, in a stable display order, and the student
 * picks (spec §16/§29). There is no FIFO, FEFO, oldest-purchase,
 * largest-balance or nearest-expiry preference anywhere in this class —
 * inventing one would silently spend a package the student did not mean
 * to spend, and the explicit choice is what makes
 * `bookings.package_entitlement_id` a truthful record of intent rather
 * than a guess.
 *
 * Matching is on STABLE IDS from the package's FROZEN
 * PackageAcademicContext (spec §15) — never display labels, and never a
 * re-resolution onto whatever CurriculumVersion happens to be published
 * now. A package sold under Curriculum v2 keeps matching as v2 after v3
 * ships; that is the point of freezing it.
 *
 * Fail-closed on legacy data (spec §35/§36): an entitlement whose
 * proposal has no frozen academic context cannot be fuzzy-matched into
 * the structured path on Subject alone, so it is never returned here.
 */
final class PackageBookingEntitlementResolver
{
    public function __construct(
        private readonly PackageEntitlementService $entitlements,
    ) {}

    /**
     * Every entitlement eligible to fund a booking for this exact
     * context, ordered for display only.
     *
     * $lessonEndsAt, when supplied, additionally drops entitlements
     * that would expire before the lesson finishes (spec §26) — booking
     * a lesson that provably cannot consume is not an eligible choice.
     *
     * @return Collection<int, StudentPackageEntitlement>
     */
    public function eligibleFor(
        User $student,
        int $instructorId,
        BookingAcademicContextData $context,
        ?CarbonImmutable $lessonEndsAt = null,
    ): Collection {
        return StudentPackageEntitlement::query()
            ->forStudent((int) $student->id)
            ->forInstructor($instructorId)
            ->active()
            // Structured identity must exist AND match on every stable
            // id. whereHas through the proposal keeps legacy,
            // context-free entitlements out by construction.
            ->whereHas('proposal.academicContext', fn ($q) => $q
                ->where('education_system_id', $context->educationSystemId)
                ->where('education_system_level_id', $context->educationSystemLevelId)
                ->where('subject_id', $context->subjectId)
                ->where('curriculum_id', $context->curriculumId)
                ->where('curriculum_version_id', $context->curriculumVersionId))
            ->with(['proposal.academicContext', 'proposal.packageBenefitRule'])
            ->orderBy('created_at')
            ->get()
            ->filter(fn (StudentPackageEntitlement $entitlement): bool => $this->isSelectable($entitlement, $lessonEndsAt))
            ->values();
    }

    /**
     * Whether one specific entitlement may fund this booking — the
     * server-side authorization the student's posted choice is checked
     * against (spec §40: never trust a posted entitlement UUID).
     *
     * Deliberately re-derives the answer from eligibleFor() rather than
     * re-implementing the predicate, so the list the student was shown
     * and the rule their submission is judged by cannot drift apart.
     */
    public function isEligible(
        User $student,
        int $instructorId,
        BookingAcademicContextData $context,
        string $entitlementId,
        ?CarbonImmutable $lessonEndsAt = null,
    ): bool {
        return $this->eligibleFor($student, $instructorId, $context, $lessonEndsAt)
            ->contains(fn (StudentPackageEntitlement $entitlement): bool => (string) $entitlement->id === $entitlementId);
    }

    /**
     * Capacity and settlement checks that cannot be expressed as SQL
     * against the entitlement row alone.
     *
     * `availableToBook()` (remaining − reserved) is what gates
     * selection, NOT `remaining_quantity`: a package with 1 unit left
     * that is already committed to a scheduled lesson must not be
     * offered again (spec §19/§27).
     */
    private function isSelectable(StudentPackageEntitlement $entitlement, ?CarbonImmutable $lessonEndsAt): bool
    {
        // Enforces expiry synchronously rather than trusting the sweep,
        // so a lapsed entitlement is never offered even if no job has
        // run since it expired.
        $entitlement = $this->entitlements->expireIfNeeded($entitlement);

        if (! $entitlement->status->isConsumable()) {
            return false;
        }

        if ($this->entitlements->availableToBook($entitlement) < 1) {
            return false;
        }

        if (! $this->isSettled($entitlement)) {
            return false;
        }

        return $this->fitsInsideValidity($entitlement, $lessonEndsAt);
    }

    /**
     * The purchase behind this balance must actually have been paid.
     * Settlement is what creates an entitlement in the first place
     * (Phase 4B.3), so this is defense-in-depth against a balance
     * created by some other path — never the primary guarantee.
     */
    private function isSettled(StudentPackageEntitlement $entitlement): bool
    {
        return StudentPackagePurchase::query()
            ->where('proposal_id', $entitlement->proposal_id)
            ->where('status', PackagePurchaseStatus::Paid)
            ->exists();
    }

    /**
     * Spec §26 — the lesson must FINISH inside the validity window,
     * because Phase 4C's approved policy is "delivered before expiry".
     * A 23:30 start with a 60-minute duration against a 00:00 expiry
     * must not be offered.
     *
     * Compared as absolute UTC instants. Timezone is a display concern
     * only and never enters this comparison; a null expiry imposes no
     * restriction at all.
     */
    private function fitsInsideValidity(StudentPackageEntitlement $entitlement, ?CarbonImmutable $lessonEndsAt): bool
    {
        if ($lessonEndsAt === null || $entitlement->expires_at === null) {
            return true;
        }

        return $lessonEndsAt->utc()->lessThanOrEqualTo(
            CarbonImmutable::instance($entitlement->expires_at)->utc(),
        );
    }
}
