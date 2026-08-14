<?php

declare(strict_types=1);

namespace App\Package\Services;

use App\Booking\Contracts\BookingTypeRepositoryInterface;
use App\Booking\DTOs\BookingAcademicContextData;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Exceptions\BookingException;
use App\Booking\Services\BookingAcademicContextResolver;
use App\Booking\Support\AcademicFlowCopy;
use App\Booking\Types\PaidOneToOneType;
use App\Country\Enums\CountryFeature;
use App\Curriculum\Exceptions\InstructorAcademicEligibilityException;
use App\Curriculum\Services\InstructorAcademicEligibilityResolver;
use App\Models\AcademicLevel;
use App\Models\Booking;
use App\Models\InstructorPackageProposal;
use App\Models\PackageAcademicContext;
use App\Models\PackageBenefitRule;
use App\Models\Subject;
use App\Models\User;
use App\Package\DTOs\CreatePackageProposalData;
use App\Package\DTOs\ResolvedPackagePriceData;
use App\Package\Enums\InstructorPackageProposalStatus;
use App\Package\Exceptions\InvalidPackageProposalTransitionException;
use App\Package\Exceptions\PackageException;
use App\Services\AuditTrailService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The single authoritative writer of InstructorPackageProposal — every
 * state transition and every price/quantity field on the model goes
 * through this service. Mirrors InstructorCompensationAgreementService/
 * InstructorWithdrawalService's shape: row-locked transactions, an
 * enum-owned transition guard, AuditTrailService on every mutation.
 *
 * Acceptance hands off to PackagePurchaseService (Phase 4B.2): this
 * service still owns every proposal transition, but the commercial
 * record that follows acceptance, and the gateway checkout behind it,
 * belong to the purchase/payment layers — see
 * docs/generic-payable-payment-foundation.md. Entitlement creation is
 * no longer reachable from here at all; it moves to verified
 * settlement in Phase 4B.3.
 */
final class InstructorPackageProposalService
{
    private const string LOG_NAME = 'instructor_package_proposals';

    public function __construct(
        private readonly AuditTrailService $audit,
        private readonly PackagePricingService $pricing,
        private readonly BookingTypeRepositoryInterface $bookingTypes,
        private readonly PackagePurchaseService $purchases,
        private readonly BookingAcademicContextResolver $academicContext,
        private readonly InstructorAcademicEligibilityResolver $instructorEligibility,
    ) {}

    /**
     * Whether this student must go through the structured
     * country-aware package flow. Gated by
     * CountryFeature::CountryAcademicPackages — deliberately NOT the
     * demo flow's CountryAcademicBooking, which would tie packages to
     * the demo-lessons switch (see that enum case).
     */
    public function structuredContextRequiredFor(User $student): bool
    {
        if (! $this->academicContext->isEnabledGlobally(CountryFeature::CountryAcademicPackages)) {
            return false;
        }

        return $this->academicContext->isEnabledForCountry(
            CountryFeature::CountryAcademicPackages,
            $this->academicContext->studentCountry($student),
        );
    }

    /**
     * A student is eligible only once they have an existing, real
     * relationship with the instructor — mirrors
     * MessagingEligibilityService's direct-query pattern (no shared
     * relationship table/service exists in this codebase). Deliberately
     * a Confirmed-or-Completed, Paid booking only: a package proposal is
     * for an existing paying student, never a free-demo-only lead.
     */
    public function hasValidRelationship(User $instructor, User $student): bool
    {
        return Booking::query()
            ->where('instructor_id', $instructor->id)
            ->where('student_id', $student->id)
            ->where('payment_status', BookingPaymentStatus::Paid)
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Completed])
            ->exists();
    }

    /** @return Collection<int, User> distinct students this instructor may propose a package to */
    public function eligibleStudentsFor(User $instructor): Collection
    {
        $studentIds = Booking::query()
            ->where('instructor_id', $instructor->id)
            ->where('payment_status', BookingPaymentStatus::Paid)
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Completed])
            ->distinct()
            ->pluck('student_id');

        return User::query()->whereKey($studentIds)->orderBy('name')->get();
    }

    /**
     * Non-persisted, read-only preview used by the instructor
     * Livewire flow to show an estimated price as they fill the form —
     * calls the exact same resolution create()/submit() use, so the
     * preview and the eventual authoritative snapshot can never
     * disagree in method, only in timing (a price change between
     * preview and submit is caught by submit()'s own re-resolve).
     *
     * @throws PackageException when no matching price is configured
     */
    public function previewPrice(User $student, User $instructor, PackageBenefitRule $rule, Subject $subject, ?AcademicLevel $academicLevel): ResolvedPackagePriceData
    {
        $bookingType = $this->bookingTypes->requireActiveByKey(PaidOneToOneType::KEY);

        return $this->pricing->resolve($student, $instructor, $bookingType, $subject, $academicLevel, (int) $bookingType->duration_minutes, $rule->paid_quantity);
    }

    /**
     * Non-persisted academic-context preview for the instructor's
     * Livewire form — the same resolution submit() performs, so the
     * context shown and the context frozen can differ only in timing,
     * never in method. Returns null when the structured flow does not
     * apply to this student.
     *
     * @throws PackageException on an incomplete/stale/ineligible selection
     */
    public function previewContext(User $student, User $instructor, ?string $educationSystemId, ?string $educationSystemLevelId, Subject $subject): ?BookingAcademicContextData
    {
        return $this->resolveStructuredContext($student, $instructor, $educationSystemId, $educationSystemLevelId, $subject);
    }

    /** @throws PackageException on any ineligible input (relationship, inactive rule, unresolvable price) */
    public function create(CreatePackageProposalData $data): InstructorPackageProposal
    {
        $instructor = User::findOrFail($data->instructorId);
        $student = User::findOrFail($data->studentId);

        if (! $this->hasValidRelationship($instructor, $student)) {
            throw new PackageException('This instructor has no existing paid relationship with this student.');
        }

        $rule = PackageBenefitRule::query()->active()->find($data->packageBenefitRuleId);

        if ($rule === null) {
            throw new PackageException('The selected package rule is not available.');
        }

        $subject = Subject::query()->find($data->subjectId);

        if ($subject === null) {
            throw new PackageException('The selected subject is not available.');
        }

        // Structured flow: the academic context is resolved server-side
        // and the AcademicLevel is DERIVED from it, so the instructor's
        // posted academicLevelId is never what decides the package's
        // identity. Legacy flow (feature off for this student's
        // country) keeps the previous free Subject+AcademicLevel shape.
        $context = $this->resolveStructuredContext($student, $instructor, $data->educationSystemId, $data->educationSystemLevelId, $subject);

        $academicLevel = $context !== null
            ? AcademicLevel::query()->find($context->academicLevelId)
            : ($data->academicLevelId !== null ? AcademicLevel::query()->find($data->academicLevelId) : null);

        $bookingType = $this->bookingTypes->requireActiveByKey(PaidOneToOneType::KEY);
        $durationMinutes = (int) $bookingType->duration_minutes;

        $price = $this->pricing->resolve($student, $instructor, $bookingType, $subject, $academicLevel, $durationMinutes, $rule->paid_quantity);

        return DB::transaction(function () use ($instructor, $student, $rule, $subject, $academicLevel, $context, $bookingType, $durationMinutes, $price): InstructorPackageProposal {
            $proposal = InstructorPackageProposal::query()->create([
                'instructor_id' => $instructor->id,
                'student_id' => $student->id,
                'package_benefit_rule_id' => $rule->id,
                'subject_id' => $subject->id,
                'academic_level_id' => $academicLevel?->id,
                // The instructor's structured SELECTION, kept so
                // submit() can re-resolve from stable ids rather than
                // trusting stale browser state (§13).
                'education_system_id' => $context?->educationSystemId,
                'education_system_level_id' => $context?->educationSystemLevelId,
                'booking_type_id' => $bookingType->id,
                'duration_minutes' => $durationMinutes,
                'country_id' => $price->countryId,
                'currency_id' => $price->currencyId,
                'currency_code' => $price->currencyCode,
                'unit_price_minor' => $price->unitPriceMinor,
                'paid_quantity' => $rule->paid_quantity,
                'bonus_quantity' => $rule->bonus_quantity,
                'total_quantity' => $rule->total_quantity,
                // Snapshotted alongside the quantities, for the same
                // reason: a later admin edit to the offer's validity
                // must never change an already-created proposal.
                'validity_days' => $rule->validity_days,
                'calculated_price_minor' => $price->calculatedPriceMinor,
                'final_price_minor' => $price->calculatedPriceMinor,
                'status' => InstructorPackageProposalStatus::Draft,
                'created_by' => $instructor->id,
                'updated_by' => $instructor->id,
            ]);

            $this->audit->logUser($instructor, self::LOG_NAME, 'package_created', sprintf('Package proposal created for student "%s".', $student->name), $proposal, $this->metadata($proposal));

            return $proposal->refresh();
        });
    }

    /** Draft only — re-runs pricing resolution, e.g. after a StudentLessonPrice change. */
    public function recalculate(InstructorPackageProposal $proposal): InstructorPackageProposal
    {
        $this->assertStatus($proposal, InstructorPackageProposalStatus::Draft);

        return DB::transaction(function () use ($proposal): InstructorPackageProposal {
            $proposal = InstructorPackageProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($proposal, InstructorPackageProposalStatus::Draft);

            $price = $this->resolvePriceFor($proposal);

            $proposal->fill([
                'country_id' => $price->countryId,
                'currency_id' => $price->currencyId,
                'currency_code' => $price->currencyCode,
                'unit_price_minor' => $price->unitPriceMinor,
                'calculated_price_minor' => $price->calculatedPriceMinor,
                'final_price_minor' => $price->calculatedPriceMinor,
            ])->save();

            return $proposal->refresh();
        });
    }

    /**
     * Draft -> Submitted, and the moment the package's identity becomes
     * history.
     *
     * Follows the Demo flow's "resolve twice" rule (§13): everything
     * shown during drafting was a preview, and NONE of it is trusted
     * here. Immediately before persistence this re-resolves the
     * academic context from stable ids, re-checks that the instructor
     * is still eligible to teach it, and re-resolves the price — then
     * freezes all three, atomically. If configuration changed between
     * preview and submit, the submit-time result is authoritative and a
     * now-invalid selection fails rather than persisting silently.
     *
     * The frozen PackageAcademicContext is what every later booking
     * matches against, so it is written here and never again.
     */
    public function submit(InstructorPackageProposal $proposal, User $instructor): InstructorPackageProposal
    {
        return DB::transaction(function () use ($proposal, $instructor): InstructorPackageProposal {
            $proposal = InstructorPackageProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            $this->assertTransition($proposal, InstructorPackageProposalStatus::Submitted);

            $subject = Subject::query()->findOrFail($proposal->subject_id);

            // Re-resolved from the proposal's own stored ids, never
            // from anything the browser still holds.
            $context = $this->resolveStructuredContext(
                $proposal->student,
                $proposal->instructor,
                $proposal->education_system_id,
                $proposal->education_system_level_id,
                $subject,
            );

            $price = $this->resolvePriceFor($proposal);

            $proposal->fill([
                'country_id' => $price->countryId,
                'currency_id' => $price->currencyId,
                'currency_code' => $price->currencyCode,
                'unit_price_minor' => $price->unitPriceMinor,
                'calculated_price_minor' => $price->calculatedPriceMinor,
                'final_price_minor' => $price->calculatedPriceMinor,
                // Kept in lockstep with the frozen snapshot so the
                // legacy compatibility column can never disagree with
                // the authoritative academic truth (§2).
                'academic_level_id' => $context?->academicLevelId ?? $proposal->academic_level_id,
                'status' => InstructorPackageProposalStatus::Submitted,
                'submitted_at' => now(),
            ])->save();

            if ($context !== null) {
                $this->freezeAcademicContext($proposal, $context);
            }

            $this->audit->logUser($instructor, self::LOG_NAME, 'package_submitted', 'Package proposal submitted for admin review.', $proposal, $this->metadata($proposal));

            return $proposal->refresh();
        });
    }

    /** Convenience used by the instructor Livewire flow's single "Submit" action — create() and submit() remain independently callable/testable. */
    public function proposeAndSubmit(CreatePackageProposalData $data): InstructorPackageProposal
    {
        return DB::transaction(function () use ($data): InstructorPackageProposal {
            $proposal = $this->create($data);

            return $this->submit($proposal, User::findOrFail($data->instructorId));
        });
    }

    /**
     * Submitted -> Approved. An optional override amount (already in
     * minor units, in the proposal's locked currency — never a
     * separately-chosen currency) requires a non-empty reason; both are
     * audited via AuditTrailService::logOverride().
     *
     * @throws PackageException when an override is given with no reason
     */
    public function approve(InstructorPackageProposal $proposal, User $admin, ?int $overridePriceMinor, ?string $overrideReason): InstructorPackageProposal
    {
        if ($overridePriceMinor !== null && trim((string) $overrideReason) === '') {
            throw new PackageException('An override reason is required when changing the final price.');
        }

        return DB::transaction(function () use ($proposal, $admin, $overridePriceMinor, $overrideReason): InstructorPackageProposal {
            $proposal = InstructorPackageProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            $this->assertTransition($proposal, InstructorPackageProposalStatus::Approved);

            $finalPriceMinor = $overridePriceMinor ?? $proposal->calculated_price_minor;

            $proposal->fill([
                'override_price_minor' => $overridePriceMinor,
                'overridden_by' => $overridePriceMinor !== null ? $admin->id : null,
                'overridden_at' => $overridePriceMinor !== null ? now() : null,
                'override_reason' => $overridePriceMinor !== null ? $overrideReason : null,
                'final_price_minor' => $finalPriceMinor,
                'status' => InstructorPackageProposalStatus::Approved,
                'approved_at' => now(),
            ])->save();

            if ($overridePriceMinor !== null) {
                $this->audit->logOverride($admin, self::LOG_NAME, 'package_price_overridden', 'Package proposal final price overridden.', (string) $overrideReason, $proposal, $this->metadata($proposal));
            }

            $this->audit->logUser($admin, self::LOG_NAME, 'package_approved', 'Package proposal approved.', $proposal, $this->metadata($proposal));

            return $proposal->refresh();
        });
    }

    public function reject(InstructorPackageProposal $proposal, User $admin, string $reason): InstructorPackageProposal
    {
        if (trim($reason) === '') {
            throw new PackageException('A rejection reason is required.');
        }

        return DB::transaction(function () use ($proposal, $admin, $reason): InstructorPackageProposal {
            $proposal = InstructorPackageProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            $this->assertTransition($proposal, InstructorPackageProposalStatus::Rejected);

            $proposal->fill([
                'status' => InstructorPackageProposalStatus::Rejected,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            $this->audit->logUser($admin, self::LOG_NAME, 'package_rejected', 'Package proposal rejected.', $proposal, [...$this->metadata($proposal), 'reason' => $reason]);

            return $proposal->refresh();
        });
    }

    public function cancel(InstructorPackageProposal $proposal, User $actor): InstructorPackageProposal
    {
        return DB::transaction(function () use ($proposal, $actor): InstructorPackageProposal {
            $proposal = InstructorPackageProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            $this->assertTransition($proposal, InstructorPackageProposalStatus::Cancelled);

            $proposal->fill([
                'status' => InstructorPackageProposalStatus::Cancelled,
                'cancelled_at' => now(),
            ])->save();

            $this->audit->logUser($actor, self::LOG_NAME, 'package_cancelled', 'Package proposal cancelled.', $proposal, $this->metadata($proposal));

            return $proposal->refresh();
        });
    }

    /**
     * Approved -> Accepted, creating the student's
     * StudentPackagePurchase in the SAME transaction. A proposal can
     * never be Accepted without its purchase existing, and vice versa.
     *
     * Phase 4B.2 CHANGED what acceptance produces. It used to activate
     * a StudentPackageEntitlement immediately; it now creates a
     * PendingPayment purchase and NOTHING usable. The lesson balance is
     * created only after verified settlement (Phase 4B.3), so a student
     * can no longer obtain lessons by accepting an offer they have not
     * paid for.
     *
     * Duplicate acceptance is impossible on two independent levels: the
     * status guard (Accepted is terminal, so a second call fails
     * assertTransition) and a UNIQUE index on
     * student_package_purchases.proposal_id.
     */
    public function acceptProposal(InstructorPackageProposal $proposal, User $student): InstructorPackageProposal
    {
        return DB::transaction(function () use ($proposal, $student): InstructorPackageProposal {
            $proposal = InstructorPackageProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            $this->assertTransition($proposal, InstructorPackageProposalStatus::Accepted);

            if ($proposal->student_id !== $student->id) {
                throw new PackageException('This package was not offered to you.');
            }

            // Honours the proposal's existing acceptance-expiry column
            // rather than redesigning it: nothing sets `expires_at`
            // today, but if an offer ever carries a deadline, a lapsed
            // one must not still be acceptable. This is the OFFER
            // deadline — unrelated to `validity_days` (how long the
            // lessons stay usable once paid for).
            if ($proposal->expires_at !== null && $proposal->expires_at->isPast()) {
                throw new PackageException('This package offer has expired.');
            }

            $proposal->fill([
                'status' => InstructorPackageProposalStatus::Accepted,
                'accepted_at' => now(),
            ])->save();

            $purchase = $this->purchases->createFromAcceptedProposal($proposal->refresh());

            $this->audit->logUser($student, self::LOG_NAME, 'package_accepted', 'Package proposal accepted by student — payment pending.', $proposal, [
                ...$this->metadata($proposal),
                'purchase_id' => $purchase->id,
                'purchase_reference' => $purchase->reference,
                'purchase_status' => $purchase->status->value,
            ]);

            return $proposal->refresh();
        });
    }

    /**
     * Approved -> Cancelled, initiated by the student declining the
     * offer. Deliberately reuses the existing Cancelled state rather
     * than adding a student-specific "declined" status — the proposal
     * is simply off the table, and the instructor may submit a new one.
     * No entitlement is created.
     */
    public function declineProposal(InstructorPackageProposal $proposal, User $student): InstructorPackageProposal
    {
        return DB::transaction(function () use ($proposal, $student): InstructorPackageProposal {
            $proposal = InstructorPackageProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            $this->assertTransition($proposal, InstructorPackageProposalStatus::Cancelled);

            if ($proposal->student_id !== $student->id) {
                throw new PackageException('This package was not offered to you.');
            }

            $proposal->fill([
                'status' => InstructorPackageProposalStatus::Cancelled,
                'cancelled_at' => now(),
            ])->save();

            $this->audit->logUser($student, self::LOG_NAME, 'package_declined', 'Package proposal declined by student.', $proposal, $this->metadata($proposal));

            return $proposal->refresh();
        });
    }

    /**
     * Resolves the structured academic context for a package, or null
     * when the country-aware packages feature is not in effect for this
     * student's country (legacy Subject+AcademicLevel path).
     *
     * The student's Country is always server-resolved — an instructor
     * never chooses it, and a posted country/currency is never trusted
     * (§4). The Curriculum is RESOLVED rather than selected: the
     * shared resolver auto-resolves it when the context determines
     * exactly one, and refuses rather than guessing otherwise (§11).
     *
     * Instructor eligibility is enforced HERE, at proposal time, not
     * deferred to booking (§10) — and again on every call, so submit()
     * re-checks it independently of whatever create() saw.
     *
     * @throws PackageException on an incomplete/stale/ineligible selection
     */
    private function resolveStructuredContext(
        User $student,
        User $instructor,
        ?string $educationSystemId,
        ?string $educationSystemLevelId,
        Subject $subject,
    ): ?BookingAcademicContextData {
        try {
            $context = $this->academicContext->resolve(
                student: $student,
                feature: CountryFeature::CountryAcademicPackages,
                copy: AcademicFlowCopy::forPackage(),
                educationSystemId: $educationSystemId,
                educationSystemLevelId: $educationSystemLevelId,
                subjectId: $subject->id,
                curriculumId: null,
                autoResolveCurriculum: true,
            );
        } catch (BookingException $e) {
            throw new PackageException($e->getMessage());
        }

        if ($context === null) {
            return null;
        }

        try {
            $this->instructorEligibility->assertEligible($instructor, $context->toAcademicContextData());
        } catch (InstructorAcademicEligibilityException $e) {
            throw new PackageException($e->getMessage());
        }

        return $context;
    }

    /**
     * Writes the package's immutable academic identity exactly once.
     *
     * firstOrCreate on the unique proposal_id: a retried submit returns
     * the existing snapshot rather than violating the constraint. The
     * row is PreventsUpdates, so even this service cannot rewrite it
     * afterwards — that is the mechanism behind "a later rename or a
     * newly published CurriculumVersion never rewrites an existing
     * package" (§3/§16).
     */
    private function freezeAcademicContext(InstructorPackageProposal $proposal, BookingAcademicContextData $context): PackageAcademicContext
    {
        return PackageAcademicContext::query()->firstOrCreate(
            ['proposal_id' => $proposal->id],
            [
                'country_id' => $context->countryId,
                'country_code' => $context->countryCode,
                'country_name' => $context->countryName,
                'education_system_id' => $context->educationSystemId,
                'education_system_code' => $context->educationSystemCode,
                'education_system_name' => $context->educationSystemName,
                'academic_level_id' => $context->academicLevelId,
                'academic_level_name' => $context->academicLevelName,
                'education_system_level_id' => $context->educationSystemLevelId,
                'level_term' => $context->levelTerm,
                'level_value' => $context->levelValue,
                'level_display' => $context->levelDisplay,
                'normalized_grade' => $context->normalizedGrade,
                'subject_id' => $context->subjectId,
                'subject_name' => $context->subjectName,
                'subject_slug' => $context->subjectSlug,
                'curriculum_id' => $context->curriculumId,
                'curriculum_name' => $context->curriculumName,
                'curriculum_slug' => $context->curriculumSlug,
                'curriculum_version_id' => $context->curriculumVersionId,
                'curriculum_version_number' => $context->curriculumVersionNumber,
            ],
        );
    }

    private function resolvePriceFor(InstructorPackageProposal $proposal): ResolvedPackagePriceData
    {
        $subject = Subject::query()->findOrFail($proposal->subject_id);
        $academicLevel = $proposal->academic_level_id !== null ? AcademicLevel::query()->find($proposal->academic_level_id) : null;
        $bookingType = $this->bookingTypes->requireActiveByKey(PaidOneToOneType::KEY);

        return $this->pricing->resolve($proposal->student, $proposal->instructor, $bookingType, $subject, $academicLevel, (int) $proposal->duration_minutes, (int) $proposal->paid_quantity);
    }

    private function assertStatus(InstructorPackageProposal $proposal, InstructorPackageProposalStatus $expected): void
    {
        if ($proposal->status !== $expected) {
            throw new PackageException(sprintf('This action requires status "%s", but the proposal is "%s".', $expected->label(), $proposal->status->label()));
        }
    }

    private function assertTransition(InstructorPackageProposal $proposal, InstructorPackageProposalStatus $to): void
    {
        if (! $proposal->status->canTransitionTo($to)) {
            throw InvalidPackageProposalTransitionException::between($proposal->status, $to);
        }
    }

    /** @return array<string, mixed> */
    private function metadata(InstructorPackageProposal $proposal): array
    {
        return [
            'instructor_id' => $proposal->instructor_id,
            'instructor_name' => $proposal->instructor?->name,
            'student_id' => $proposal->student_id,
            'student_name' => $proposal->student?->name,
            'package_benefit_rule_id' => $proposal->package_benefit_rule_id,
            'package_benefit_rule_name' => $proposal->packageBenefitRule?->name,
            'calculated_price_minor' => $proposal->calculated_price_minor,
            'final_price_minor' => $proposal->final_price_minor,
            'currency_code' => $proposal->currency_code,
        ];
    }
}
