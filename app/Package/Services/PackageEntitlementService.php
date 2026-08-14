<?php

declare(strict_types=1);

namespace App\Package\Services;

use App\Models\InstructorPackageProposal;
use App\Models\Lesson;
use App\Models\StudentPackageEntitlement;
use App\Models\StudentPackageEntitlementConsumption;
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
 * `createFromProposal()` is called by settlement (Phase 4B.3).
 * `consumeForLesson()` is called by ConsumePackageEntitlementOnLessonCompleted
 * (Phase 4C) and is the authoritative consumption path — it reads the
 * lesson's explicit `package_entitlement_id` attribution and never
 * infers a package from student/instructor/subject matching.
 *
 * Expiry is enforced synchronously by usable()/consumeForLesson();
 * the daily sweep is housekeeping over the same expireIfNeeded() rule.
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

    /**
     * Whether this entitlement may currently be drawn against.
     *
     * Expiry is enforced HERE, synchronously, rather than relying on a
     * scheduled sweep: a sweep always has a window in which an expired
     * entitlement still looks usable, and correctness must not depend
     * on how recently a job ran. The sweep (expireDue()) exists only so
     * dashboards agree with reality for entitlements nobody has touched.
     */
    public function usable(StudentPackageEntitlement $entitlement): bool
    {
        $entitlement = $this->expireIfNeeded($entitlement);

        return $entitlement->status->isConsumable() && $this->remainingLessons($entitlement) > 0;
    }

    /**
     * Transitions a lapsed Active entitlement to Expired, and returns
     * the entitlement either way.
     *
     * Boundary rule: an entitlement is expired at `expires_at`, not
     * after it — "valid for 90 days" ends the moment the 90 days are
     * up. A null `expires_at` means no time limit and never expires.
     *
     * Only Active may expire. Completed (every lesson used) and
     * Cancelled are terminal and keep their own meaning: `Expired` says
     * "time ran out, possibly with lessons unused", which would be a
     * lie about either of them.
     */
    public function expireIfNeeded(StudentPackageEntitlement $entitlement): StudentPackageEntitlement
    {
        if (! $this->hasLapsed($entitlement)) {
            return $entitlement;
        }

        return DB::transaction(function () use ($entitlement): StudentPackageEntitlement {
            $entitlement = StudentPackageEntitlement::query()->whereKey($entitlement->id)->lockForUpdate()->firstOrFail();

            // Re-checked under the lock: a concurrent consumption may
            // have completed it, or another caller may have expired it
            // already, between the check above and this transaction.
            if (! $this->hasLapsed($entitlement)) {
                return $entitlement;
            }

            $entitlement->fill(['status' => PackageEntitlementStatus::Expired])->save();

            $this->audit->logSystem(
                self::LOG_NAME,
                'package_entitlement_expired',
                sprintf('Package entitlement expired with %d unused lesson(s).', $entitlement->remaining_quantity),
                $entitlement,
                $this->metadata($entitlement) + ['expires_at' => $entitlement->expires_at?->toIso8601String()],
            );

            return $entitlement->refresh();
        });
    }

    /**
     * Housekeeping only — keeps admin lists and dashboards accurate for
     * entitlements nobody has read. Correctness never depends on this
     * having run, and it deliberately reuses expireIfNeeded() so there
     * is exactly one expiry rule in the codebase.
     *
     * @return int how many entitlements were expired
     */
    public function expireDue(int $limit = 500): int
    {
        $expired = 0;

        StudentPackageEntitlement::query()
            ->where('status', PackageEntitlementStatus::Active)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->limit($limit)
            ->each(function (StudentPackageEntitlement $entitlement) use (&$expired): void {
                if ($this->expireIfNeeded($entitlement)->status === PackageEntitlementStatus::Expired) {
                    $expired++;
                }
            });

        return $expired;
    }

    /**
     * Draws one unit for a completed, package-funded lesson. The
     * authoritative consumption path — nothing else may consume.
     *
     * Attribution is read, never inferred: only
     * `lesson.package_entitlement_id` decides whether a package is
     * involved and which one. A lesson with no attribution is an
     * ordinary lesson and returns null without touching anything; a
     * lesson WITH attribution whose entitlement cannot be drawn is an
     * integrity problem and throws.
     *
     * Idempotent per lesson on two independent levels: the ledger
     * lookup below, and `UNIQUE(lesson_id)` if two workers race past
     * it. A replay returns the existing consumption rather than
     * throwing — completion is legitimately re-delivered.
     *
     * @return StudentPackageEntitlementConsumption|null null when the lesson is not package-funded
     *
     * @throws PackageException when an attributed entitlement cannot be consumed
     */
    public function consumeForLesson(Lesson $lesson, ?User $actor = null): ?StudentPackageEntitlementConsumption
    {
        if ($lesson->package_entitlement_id === null) {
            return null;
        }

        $existing = StudentPackageEntitlementConsumption::query()->forLesson((string) $lesson->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        // Expired outside the transaction so the status change survives
        // a subsequent refusal (see consumeLesson()).
        $entitlement = StudentPackageEntitlement::query()->find($lesson->package_entitlement_id);

        if ($entitlement === null) {
            throw new PackageException('This lesson was funded by a package that no longer exists.');
        }

        $this->expireIfNeeded($entitlement);

        return DB::transaction(function () use ($lesson, $entitlement, $actor): StudentPackageEntitlementConsumption {
            $entitlement = StudentPackageEntitlement::query()->whereKey($entitlement->id)->lockForUpdate()->firstOrFail();

            // Re-checked under the lock — never trust values read before it.
            $replay = StudentPackageEntitlementConsumption::query()->forLesson((string) $lesson->id)->first();

            if ($replay !== null) {
                return $replay;
            }

            $this->assertConsumableFor($lesson, $entitlement);

            // One timestamp for the ledger row and for completed_at, so
            // "used at" and "finished at" cannot disagree.
            $consumedAt = now();

            $consumption = StudentPackageEntitlementConsumption::query()->create([
                'entitlement_id' => $entitlement->id,
                'lesson_id' => $lesson->id,
                'student_id' => $entitlement->student_id,
                'instructor_id' => $entitlement->instructor_id,
                'consumed_at' => $consumedAt,
            ]);

            $used = $entitlement->used_quantity + 1;
            $isNowComplete = $used >= $entitlement->total_quantity;

            $entitlement->fill([
                'used_quantity' => $used,
                'status' => $isNowComplete ? PackageEntitlementStatus::Completed : PackageEntitlementStatus::Active,
                'completed_at' => $isNowComplete ? $consumedAt : null,
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
                $this->metadata($entitlement) + ['lesson_id' => $lesson->id, 'consumption_id' => $consumption->id],
            );

            return $consumption;
        });
    }

    /**
     * @throws PackageException when the attributed entitlement is not a
     *                          legitimate source for this lesson
     */
    private function assertConsumableFor(Lesson $lesson, StudentPackageEntitlement $entitlement): void
    {
        // The attribution must belong to the same pair. A mismatch means
        // the reference was tampered with or a booking was reassigned —
        // either way, never quietly consume someone else's package.
        if ((int) $entitlement->student_id !== (int) $lesson->student_id) {
            throw new PackageException('This package belongs to a different student.');
        }

        if ((int) $entitlement->instructor_id !== (int) $lesson->instructor_id) {
            throw new PackageException('This package is for a different instructor.');
        }

        if ($entitlement->subject_id !== null && $lesson->subject_id !== null && $entitlement->subject_id !== $lesson->subject_id) {
            throw new PackageException('This package is for a different subject.');
        }

        // Approved policy: the lesson must be DELIVERED before the
        // package expires — booking in time is not enough. Enforced
        // here rather than at booking so the rule has one home.
        if ($this->hasLapsed($entitlement)) {
            throw new PackageException('This package expired before the lesson was delivered.');
        }

        if (! $entitlement->status->isConsumable()) {
            throw new PackageException(sprintf('This package is %s and can no longer be used.', $entitlement->status->label()));
        }

        if ($entitlement->remaining_quantity < 1) {
            throw new PackageException('This package has no remaining lessons.');
        }
    }

    /** Active, time-limited, and at or past its expiry instant. */
    private function hasLapsed(StudentPackageEntitlement $entitlement): bool
    {
        return $entitlement->status === PackageEntitlementStatus::Active
            && $entitlement->expires_at !== null
            && $entitlement->expires_at->lessThanOrEqualTo(now());
    }

    /** @deprecated Prefer usable() — this does not enforce expiry. Kept for the existing Phase 4A callers/tests. */
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
        // Expired FIRST, in its own transaction, so the status change
        // survives: the refusal below throws, and a write made inside
        // that same transaction would be rolled back with it.
        $entitlement = $this->expireIfNeeded($entitlement);

        return DB::transaction(function () use ($entitlement, $actor): StudentPackageEntitlement {
            $entitlement = StudentPackageEntitlement::query()->whereKey($entitlement->id)->lockForUpdate()->firstOrFail();

            // Re-checked under the lock against the same clock, for the
            // narrow case where it lapsed between the call above and
            // this transaction. Refusing is what matters here; the
            // status write happens on the next read or in the sweep,
            // because a write in this transaction dies with the throw.
            if ($this->hasLapsed($entitlement)) {
                throw new PackageException('This package has expired and can no longer be used.');
            }

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
