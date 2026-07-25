<?php

declare(strict_types=1);

namespace App\Listeners\SupportCases;

use App\Models\User;
use App\Notifications\SupportCase\SupportCaseAssignedNotification;
use App\Notifications\SupportCase\SupportCaseCreatedNotification;
use App\Notifications\SupportCase\SupportCaseReplyNotification;
use App\Notifications\SupportCase\SupportCaseStatusChangedNotification;
use App\Services\Notifications\NotificationIdempotencyGuard;
use App\SupportCases\Enums\SupportCaseReplyVisibility;
use App\SupportCases\Enums\SupportCaseStatus;
use App\SupportCases\Events\SupportCaseAssigned;
use App\SupportCases\Events\SupportCaseCreated;
use App\SupportCases\Events\SupportCaseReplyAdded;
use App\SupportCases\Events\SupportCaseStatusChanged;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Participant (requester/assignee) notifications for the support-case
 * lifecycle (SRS §25.45). Broader admin awareness (new critical case,
 * case escalated, user replied, case reopened) flows separately
 * through the Activity Log pipeline (AuditTrailService → ActivityCreated
 * → NotifyAdminsOnActivity → NotificationMapper), matching the same
 * split already established for bookings — this listener never calls
 * AdminNotificationService directly.
 *
 * Every send is claimed through NotificationIdempotencyGuard before
 * dispatch, so a redelivered event never double-sends.
 */
final class SendSupportCaseNotifications implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly NotificationIdempotencyGuard $idempotency,
    ) {}

    public function handleCreated(SupportCaseCreated $event): void
    {
        $this->send('support-case-created', $event->case->id, $event->case->creator, new SupportCaseCreatedNotification($event->case));
    }

    public function handleAssigned(SupportCaseAssigned $event): void
    {
        $this->send(
            'support-case-assigned',
            $event->case->id.':'.$event->assignee->id.':'.$event->case->updated_at?->timestamp,
            $event->assignee,
            new SupportCaseAssignedNotification($event->case),
        );
    }

    public function handleReplyAdded(SupportCaseReplyAdded $event): void
    {
        if ($event->reply->visibility === SupportCaseReplyVisibility::InternalNote) {
            return;
        }

        $case = $event->reply->supportCase;
        $requester = $case->creator;
        $isFromRequester = $event->reply->author_id === $requester->id;

        $recipient = $isFromRequester ? $case->assignee : $requester;

        if ($recipient === null) {
            return;
        }

        $this->send(
            'support-case-reply',
            $event->reply->id,
            $recipient,
            new SupportCaseReplyNotification($case, $event->reply),
        );
    }

    public function handleStatusChanged(SupportCaseStatusChanged $event): void
    {
        $case = $event->case;
        $requester = $case->creator;

        // Requester-relevant transitions (SRS §25.45 Student/Instructor):
        // waiting-for-user, resolved, closed. Reopen notifies whichever
        // side did not trigger it; escalation stays admin-only (Activity
        // Log pipeline).
        if (in_array($event->to, [SupportCaseStatus::WaitingForUser, SupportCaseStatus::Resolved, SupportCaseStatus::Closed], strict: true)) {
            $this->send(
                'support-case-status:'.$event->to->value,
                $case->id.':'.$case->updated_at?->timestamp,
                $requester,
                new SupportCaseStatusChangedNotification($case, $event->to),
            );
        }

        if ($event->to === SupportCaseStatus::Open && $event->from->isReopenable()) {
            $recipient = $event->actor->id === $requester->id ? $case->assignee : $requester;

            if ($recipient !== null) {
                $this->send(
                    'support-case-reopened',
                    $case->id.':'.$case->updated_at?->timestamp,
                    $recipient,
                    new SupportCaseStatusChangedNotification($case, $event->to),
                );
            }
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
