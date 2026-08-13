<?php

declare(strict_types=1);

namespace App\Package\Services;

use App\Booking\Contracts\BookingTypeRepositoryInterface;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Types\PaidOneToOneType;
use App\Models\AcademicLevel;
use App\Models\Booking;
use App\Models\InstructorPackageProposal;
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
 * Deliberately does not touch payment, wallet, instructor compensation,
 * or lesson/booking entitlement creation — all explicitly out of scope
 * for this phase (see docs/architecture/domain-registry.md
 * "Personalized Packages").
 */
final class InstructorPackageProposalService
{
    private const string LOG_NAME = 'instructor_package_proposals';

    public function __construct(
        private readonly AuditTrailService $audit,
        private readonly PackagePricingService $pricing,
        private readonly BookingTypeRepositoryInterface $bookingTypes,
        private readonly PackageEntitlementService $entitlements,
    ) {}

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

        $academicLevel = $data->academicLevelId !== null ? AcademicLevel::query()->find($data->academicLevelId) : null;
        $bookingType = $this->bookingTypes->requireActiveByKey(PaidOneToOneType::KEY);
        $durationMinutes = (int) $bookingType->duration_minutes;

        $price = $this->pricing->resolve($student, $instructor, $bookingType, $subject, $academicLevel, $durationMinutes, $rule->paid_quantity);

        return DB::transaction(function () use ($instructor, $student, $rule, $subject, $academicLevel, $bookingType, $durationMinutes, $price): InstructorPackageProposal {
            $proposal = InstructorPackageProposal::query()->create([
                'instructor_id' => $instructor->id,
                'student_id' => $student->id,
                'package_benefit_rule_id' => $rule->id,
                'subject_id' => $subject->id,
                'academic_level_id' => $academicLevel?->id,
                'booking_type_id' => $bookingType->id,
                'duration_minutes' => $durationMinutes,
                'country_id' => $price->countryId,
                'currency_id' => $price->currencyId,
                'currency_code' => $price->currencyCode,
                'unit_price_minor' => $price->unitPriceMinor,
                'paid_quantity' => $rule->paid_quantity,
                'bonus_quantity' => $rule->bonus_quantity,
                'total_quantity' => $rule->total_quantity,
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

    /** Draft -> Submitted. Re-resolves price once more immediately before persistence — never trusts an earlier resolve (mirrors the Booking Demo flow's same rule). */
    public function submit(InstructorPackageProposal $proposal, User $instructor): InstructorPackageProposal
    {
        return DB::transaction(function () use ($proposal, $instructor): InstructorPackageProposal {
            $proposal = InstructorPackageProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            $this->assertTransition($proposal, InstructorPackageProposalStatus::Submitted);

            $price = $this->resolvePriceFor($proposal);

            $proposal->fill([
                'country_id' => $price->countryId,
                'currency_id' => $price->currencyId,
                'currency_code' => $price->currencyCode,
                'unit_price_minor' => $price->unitPriceMinor,
                'calculated_price_minor' => $price->calculatedPriceMinor,
                'final_price_minor' => $price->calculatedPriceMinor,
                'status' => InstructorPackageProposalStatus::Submitted,
                'submitted_at' => now(),
            ])->save();

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
     * Approved -> Accepted, and — Phase 4A — creates the student's
     * StudentPackageEntitlement in the SAME transaction. A proposal can
     * never be Accepted without its entitlement existing, and vice
     * versa.
     *
     * Duplicate acceptance is impossible on two independent levels: the
     * status guard (Accepted is terminal, so a second call fails
     * assertTransition) and a UNIQUE index on
     * student_package_entitlements.proposal_id.
     *
     * Payment is still explicitly out of scope: accepting records the
     * student's agreement and grants the lesson balance; no money moves
     * and no Booking/Lesson is created here.
     */
    public function acceptProposal(InstructorPackageProposal $proposal, User $student): InstructorPackageProposal
    {
        return DB::transaction(function () use ($proposal, $student): InstructorPackageProposal {
            $proposal = InstructorPackageProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            $this->assertTransition($proposal, InstructorPackageProposalStatus::Accepted);

            if ($proposal->student_id !== $student->id) {
                throw new PackageException('This package was not offered to you.');
            }

            $proposal->fill([
                'status' => InstructorPackageProposalStatus::Accepted,
                'accepted_at' => now(),
            ])->save();

            $entitlement = $this->entitlements->createFromProposal($proposal->refresh());

            $this->audit->logUser($student, self::LOG_NAME, 'package_accepted', 'Package proposal accepted by student.', $proposal, [
                ...$this->metadata($proposal),
                'entitlement_id' => $entitlement->id,
                'total_quantity' => $entitlement->total_quantity,
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
