<?php

declare(strict_types=1);

namespace App\Listeners\Package;

use App\Lessons\Events\LessonCompleted;
use App\Package\Exceptions\PackageException;
use App\Package\Services\PackageEntitlementService;
use App\Services\AuditTrailService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Phase 4C — the single integration point between the Lesson domain
 * and package balances.
 *
 * It listens to LessonCompleted for the same reasons
 * CreateEarningOnLessonCompleted does: that event is dispatched only
 * from LessonLifecycleService's completion paths (manual/admin and the
 * auto-completion sweep), after the finalizing transaction commits, and
 * only for the Completed outcome. Cancellations, the three no-show
 * outcomes, and technical issues never reach it, so they can never burn
 * a package unit.
 *
 * Deliberately a sibling listener rather than an extension of the
 * completion transaction: earning creation already works this way, and
 * widening that transaction to cover package state would couple two
 * independently-recoverable concerns. Safety comes from idempotency
 * instead — consumeForLesson() is a no-op on replay, and
 * UNIQUE(lesson_id) is the database's own guarantee — which is what a
 * queued, retryable listener needs.
 *
 * A lesson with no package attribution is the common case and costs one
 * null check.
 */
final class ConsumePackageEntitlementOnLessonCompleted implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly PackageEntitlementService $entitlements,
        private readonly AuditTrailService $audit,
    ) {}

    public function handle(LessonCompleted $event): void
    {
        if ($event->lesson->package_entitlement_id === null) {
            return;
        }

        try {
            $this->entitlements->consumeForLesson($event->lesson);
        } catch (PackageException $e) {
            // The lesson WAS package-funded but the balance could not be
            // drawn — an expired package, an exhausted one, or a
            // mismatched attribution. The lesson stays completed and the
            // instructor is still paid; this is a billing discrepancy
            // for an operator, not a reason to fail the lesson.
            $this->audit->logSystem(
                'student_package_entitlements',
                'package_consumption_failed',
                sprintf('A package-funded lesson could not draw on its package: %s', $e->getMessage()),
                $event->lesson,
                [
                    'lesson_id' => $event->lesson->id,
                    'entitlement_id' => $event->lesson->package_entitlement_id,
                    'student_id' => $event->lesson->student_id,
                ],
            );
        }
    }
}
