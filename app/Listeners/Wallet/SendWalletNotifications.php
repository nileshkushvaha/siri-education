<?php

declare(strict_types=1);

namespace App\Listeners\Wallet;

use App\Models\PromotionalCreditIssuance;
use App\Models\User;
use App\Notifications\Wallet\LessonRefundCreditedNotification;
use App\Notifications\Wallet\PromotionalCreditIssuedNotification;
use App\Notifications\Wallet\WalletRechargeSucceededNotification;
use App\PromotionalCredits\Events\PromotionalCreditIssued;
use App\Services\Notifications\NotificationIdempotencyGuard;
use App\Wallet\Events\LessonRefundCompleted;
use App\Wallet\Events\WalletRechargeSucceeded;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Student notifications for wallet lifecycle events. Mirrors
 * App\Listeners\Booking\SendBookingNotifications's idempotency
 * discipline: a redelivered event (queue retry after a crash
 * post-side-effect) must never double-send.
 *
 * Deliberately no failure notification: a genuine checkout failure is
 * already visible to the student in real time on the wallet page
 * (same convention as booking payments, which also never email on an
 * ordinary payment failure). A captured-but-uncredited recharge is an
 * operational concern, not a student-facing one — it is audit-logged
 * by WalletRechargeService/WalletRechargeReconciliationService instead.
 *
 * Promotional credits reuse this same listener/queue/idempotency
 * discipline for promotional credits rather than standing up a
 * parallel notification pipeline — requirement #8 "do not duplicate
 * ... notification pipelines".
 */
final class SendWalletNotifications implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly NotificationIdempotencyGuard $idempotency,
    ) {}

    public function handleRechargeSucceeded(WalletRechargeSucceeded $event): void
    {
        $key = sprintf('wallet-recharge-succeeded:%s', $event->recharge->id);

        $this->idempotency->once($key, WalletRechargeSucceededNotification::class, function () use ($event): void {
            $event->recharge->user->notify(new WalletRechargeSucceededNotification($event->recharge));
        });
    }

    /**
     * A lesson-outcome refund (instructor no-show, technical failure, admin
     * exception) credits the student's wallet from a path they never see in
     * real time — unlike a student-initiated cancellation, which already
     * states its frozen refund outcome in BookingCancelledNotification.
     *
     * Keyed on disposition id *and* version because an admin override can
     * legitimately re-resolve a disposition into a second, distinct refund;
     * the ledger's own idempotency key is scoped the same way, so the mail
     * and the credit can never disagree about what counts as "the same"
     * refund.
     */
    public function handleLessonRefundCompleted(LessonRefundCompleted $event): void
    {
        $student = $event->disposition->booking?->student;

        if (! $student instanceof User) {
            return;
        }

        $key = sprintf(
            'lesson-refund-credited:%s:v%d',
            $event->disposition->id,
            $event->disposition->version,
        );

        $this->idempotency->once($key, LessonRefundCreditedNotification::class, function () use ($student, $event): void {
            $student->notify(new LessonRefundCreditedNotification($event->disposition, $event->entry));
        });
    }

    public function handlePromotionalCreditIssued(PromotionalCreditIssued $event): void
    {
        $issuance = PromotionalCreditIssuance::query()->with(['student', 'campaign'])->find($event->issuanceId);

        if ($issuance?->student === null) {
            return;
        }

        $key = sprintf('promotional-credit-issued:%s', $issuance->id);

        $this->idempotency->once($key, PromotionalCreditIssuedNotification::class, function () use ($issuance): void {
            $issuance->student->notify(new PromotionalCreditIssuedNotification($issuance));
        });
    }
}
