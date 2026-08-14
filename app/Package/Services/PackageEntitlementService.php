<?php

declare(strict_types=1);

namespace App\Package\Services;

use App\Models\InstructorPackageProposal;
use App\Models\StudentPackageEntitlement;
use App\Models\User;
use App\Package\Enums\PackageEntitlementStatus;
use App\Package\Exceptions\PackageException;
use App\Services\AuditTrailService;
use Illuminate\Support\Facades\DB;

/**
 * The sole reader/writer of a StudentPackageEntitlement's balance, and
 * the deliberate service boundary the future booking integration will
 * call.
 *
 * Nothing calls this service in production yet, by design.
 * `createFromProposal()` waits for Phase 4B.3's settlement handler
 * (Phase 4B.2 unwired it from acceptance), and `consumeLesson()` waits
 * for Booking/Lesson integration. Both exist so the boundary is
 * designed and tested now, not so they can be wired in early.
 * `hasAvailableLessons()`/`remainingLessons()` are pure reads.
 *
 * `remaining_quantity` is never computed in PHP here: it is a stored
 * generated column (total - used) so the database is the single source
 * of that number. This service only ever moves `used_quantity`.
 */
final class PackageEntitlementService
{
    private const string LOG_NAME = 'student_package_entitlements';

    public function __construct(
        private readonly AuditTrailService $audit,
    ) {}

    /**
     * Creates the Active entitlement for a proposal.
     *
     * Phase 4B.2 UNWIRED this: acceptance no longer calls it, because
     * accepting an offer no longer grants lessons — a
     * StudentPackagePurchase is created instead and the balance appears
     * only after verified settlement. The method stays because Phase
     * 4B.3's settlement handler is its next (and only) caller, at which
     * point it also gains the `expires_at` calculation described below.
     */
    public function createFromProposal(InstructorPackageProposal $proposal): StudentPackageEntitlement
    {
        // Refreshed before returning: `remaining_quantity` is a STORED
        // GENERATED column, so it is null on the freshly-created model
        // until it is read back from the database.
        $entitlement = StudentPackageEntitlement::query()->create([
            'student_id' => $proposal->student_id,
            'instructor_id' => $proposal->instructor_id,
            'proposal_id' => $proposal->id,
            'subject_id' => $proposal->subject_id,
            'academic_level_id' => $proposal->academic_level_id,
            'booking_type_id' => $proposal->booking_type_id,
            'paid_quantity' => $proposal->paid_quantity,
            'bonus_quantity' => $proposal->bonus_quantity,
            'total_quantity' => $proposal->total_quantity,
            // Carried forward from the proposal's snapshot so the
            // entitlement records the validity that applied at the time.
            // `expires_at` is deliberately NOT computed here: an
            // absolute expiry only becomes meaningful once payment
            // activates the package, so Phase 4B.3 computes it here
            // from `validity_days` at the moment of settlement.
            'validity_days' => $proposal->validity_days,
            'used_quantity' => 0,
            'status' => PackageEntitlementStatus::Active,
            'activated_at' => now(),
        ]);

        return $entitlement->refresh();
    }

    public function hasAvailableLessons(StudentPackageEntitlement $entitlement): bool
    {
        return $entitlement->status->isConsumable() && $this->remainingLessons($entitlement) > 0;
    }

    public function remainingLessons(StudentPackageEntitlement $entitlement): int
    {
        return (int) $entitlement->remaining_quantity;
    }

    /**
     * Draws one lesson down. The ONLY mutator of `used_quantity`.
     *
     * Row-locked so two concurrent consumptions cannot both read the
     * same remaining balance; the DB CHECK (used <= total) is the
     * backstop if that lock is ever bypassed. Auto-completes the
     * entitlement when the last lesson is drawn.
     *
     * Not called from Booking yet — see class docblock.
     *
     * @throws PackageException when the entitlement is not Active or has no lessons left
     */
    public function consumeLesson(StudentPackageEntitlement $entitlement, ?User $actor = null): StudentPackageEntitlement
    {
        return DB::transaction(function () use ($entitlement, $actor): StudentPackageEntitlement {
            $entitlement = StudentPackageEntitlement::query()->whereKey($entitlement->id)->lockForUpdate()->firstOrFail();

            if (! $entitlement->status->isConsumable()) {
                throw new PackageException(sprintf('This package is %s and can no longer be used.', $entitlement->status->label()));
            }

            if ($entitlement->remaining_quantity < 1) {
                throw new PackageException('This package has no remaining lessons.');
            }

            $used = $entitlement->used_quantity + 1;
            $isNowComplete = $used >= $entitlement->total_quantity;

            $entitlement->fill([
                'used_quantity' => $used,
                'status' => $isNowComplete ? PackageEntitlementStatus::Completed : PackageEntitlementStatus::Active,
                'completed_at' => $isNowComplete ? now() : null,
            ])->save();

            $entitlement->refresh();

            $this->audit->logUser(
                $actor ?? $entitlement->student,
                self::LOG_NAME,
                $isNowComplete ? 'package_entitlement_completed' : 'package_entitlement_lesson_consumed',
                $isNowComplete
                    ? 'Package entitlement fully consumed.'
                    : 'One lesson consumed from package entitlement.',
                $entitlement,
                $this->metadata($entitlement),
            );

            return $entitlement;
        });
    }

    /** @return array<string, mixed> */
    private function metadata(StudentPackageEntitlement $entitlement): array
    {
        return [
            'student_id' => $entitlement->student_id,
            'instructor_id' => $entitlement->instructor_id,
            'proposal_id' => $entitlement->proposal_id,
            'total_quantity' => $entitlement->total_quantity,
            'used_quantity' => $entitlement->used_quantity,
            'remaining_quantity' => $entitlement->remaining_quantity,
        ];
    }
}
