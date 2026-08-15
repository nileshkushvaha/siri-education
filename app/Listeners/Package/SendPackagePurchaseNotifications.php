<?php

declare(strict_types=1);

namespace App\Listeners\Package;

use App\Models\User;
use App\Notifications\Package\PackagePurchasedInstructorNotification;
use App\Notifications\Package\PackagePurchasedStudentNotification;
use App\Package\Events\PackagePurchaseSettled;
use App\Services\Notifications\NotificationIdempotencyGuard;
use App\Services\Payment\InvoiceService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Participant notifications for a settled package purchase — the
 * package counterpart to SendBookingNotifications.
 *
 * Admins are deliberately absent. Admin visibility of a package
 * settlement already exists and stays where it belongs: the
 * `package_payment_settled` audit-trail entry written by
 * PackagePurchaseSettlementService, plus the Filament resources and
 * reconciliation queue. An audit record is not a user notification,
 * and NotificationMapper maps no package or payment-success event, so
 * no admin bell notification or email is produced by this path.
 *
 * ## Not sending twice
 *
 * Two independent guarantees, matching the booking family:
 *
 *  1. PackagePurchaseSettled is dispatched only from the `settled`
 *     branch of applySuccess(), which a replayed webhook never
 *     reaches — it returns `replayed()` instead.
 *  2. Every send is still claimed through NotificationIdempotencyGuard
 *     first, because Laravel's queued-listener delivery is
 *     at-least-once: a worker that crashes after notifying but before
 *     acking would otherwise re-notify on retry. The guard's unique
 *     index, not an in-memory check, is the actual guarantee.
 *
 * A purchase settles exactly once, so the purchase id alone is a
 * sufficient discriminator — unlike a reschedule, there is no
 * legitimate second settlement of the same purchase.
 */
final class SendPackagePurchaseNotifications implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly NotificationIdempotencyGuard $idempotency,
        private readonly InvoiceService $invoices,
    ) {}

    public function handle(PackagePurchaseSettled $event): void
    {
        $purchase = $event->purchase;
        $student = $purchase->student;
        $instructor = $purchase->proposal?->instructor;

        // Resolved here rather than read back from the table because
        // GenerateInvoiceOnPackagePurchaseSettled is a sibling queued
        // listener with no ordering guarantee against this one.
        // InvoiceService is idempotent by construction, so whichever
        // arrives first generates and the other gets the same invoice
        // back — never a second receipt for the same payment.
        $receipt = $this->invoices->generateForPackagePurchase($event->payment);

        // The receipt goes to the purchaser and to nobody else.
        if ($student instanceof User) {
            $this->send(
                'package-purchased-student',
                $purchase->id,
                $student,
                new PackagePurchasedStudentNotification($purchase, $event->payment, $event->entitlement, $receipt),
            );
        }

        // Operational content only — no amount, no payment reference,
        // no receipt. See the notification's own note on why student
        // price is not instructor earnings.
        if ($instructor instanceof User) {
            $this->send(
                'package-purchased-instructor',
                $purchase->id,
                $instructor,
                new PackagePurchasedInstructorNotification($purchase, $event->entitlement),
            );
        }
    }

    private function send(string $type, string $discriminator, User $recipient, object $notification): void
    {
        $key = sprintf('%s:%s:%d', $type, $discriminator, $recipient->id);

        $this->idempotency->once($key, $notification::class, function () use ($recipient, $notification): void {
            $recipient->notify($notification);
        });
    }
}
