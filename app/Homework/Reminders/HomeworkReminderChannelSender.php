<?php

declare(strict_types=1);

namespace App\Homework\Reminders;

use App\Homework\Enums\HomeworkReminderChannelStatus;
use App\Homework\Services\HomeworkNotificationChannelResolver;
use App\Models\HomeworkAssignment;
use App\Models\HomeworkDueReminder;
use App\Models\HomeworkReminderChannelDelivery;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\Homework\HomeworkDueReminderNotification;
use App\Services\AuditTrailService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Partial-channel idempotency. Sends each
 * notification channel independently via Notification::sendNow() with
 * an explicit single-channel array — this bypasses Laravel's
 * all-channels-in-one-call semantics entirely, so a mail failure can
 * never re-touch an already-delivered database row, and a retry only
 * ever re-attempts channels that are not yet durably resolved.
 *
 * Every (reminder, channel) pair claims its own row before sending —
 * Dispatched/Suppressed are terminal and skipped forever after;
 * Sending is a short lease (LEASE_SECONDS) so a crashed worker's
 * in-flight channel is reclaimable rather than stuck; Failed is
 * terminal only once MAX_ATTEMPTS is exhausted, otherwise it reverts
 * to Pending for a later retry. The database channel additionally
 * gets a deterministic notification UUID (derived from the reminder
 * id + channel) so even a claim-lease race that somehow let two
 * workers both attempt it collides on the notifications table's own
 * primary key — a second layer of defense, not the primary guarantee.
 */
final class HomeworkReminderChannelSender
{
    public const int MAX_ATTEMPTS = 3;

    public const int LEASE_SECONDS = 300;

    /** @var array<string, string> Laravel channel identifier => canonical short key */
    private const array CHANNEL_KEYS = [
        'database' => 'database',
        'mail' => 'mail',
        WhatsAppChannel::class => 'whatsapp',
        SmsChannel::class => 'sms',
    ];

    public function __construct(private readonly AuditTrailService $audit) {}

    /**
     * Attempts (or reclaims) every enabled channel and marks disabled
     * ones Suppressed. Safe to call repeatedly — already-resolved
     * channels are always skipped.
     */
    public function resolveAll(HomeworkDueReminder $reminder, HomeworkAssignment $assignment): void
    {
        $enabled = array_map(
            fn (string $channel): string => self::CHANNEL_KEYS[$channel] ?? $channel,
            app(HomeworkNotificationChannelResolver::class)->channels($assignment->student),
        );

        foreach (self::CHANNEL_KEYS as $laravelChannel => $key) {
            if (in_array($key, $enabled, true)) {
                $this->attempt($reminder, $assignment, $key, $laravelChannel);
            } else {
                $this->suppress($reminder, $key);
            }
        }
    }

    /** Deterministic aggregate — never itself persisted here; the caller decides how to apply it. */
    public function aggregateStatus(HomeworkDueReminder $reminder): string
    {
        $statuses = HomeworkReminderChannelDelivery::query()
            ->where('homework_due_reminder_id', $reminder->id)
            ->pluck('status');

        if ($statuses->contains(fn (HomeworkReminderChannelStatus $status): bool => in_array($status, [HomeworkReminderChannelStatus::Pending, HomeworkReminderChannelStatus::Sending], true))) {
            return 'pending';
        }

        if ($statuses->contains(HomeworkReminderChannelStatus::Failed)) {
            return 'failed';
        }

        return 'dispatched';
    }

    private function suppress(HomeworkDueReminder $reminder, string $key): void
    {
        // Concurrent workers creating rows for the same reminder's
        // different channels can deadlock on the composite unique index
        // (classic InnoDB concurrent-insert gap-lock pattern) — retried
        // automatically via DB::transaction()'s $attempts parameter.
        DB::transaction(function () use ($reminder, $key): void {
            $delivery = HomeworkReminderChannelDelivery::query()->firstOrCreate(
                ['homework_due_reminder_id' => $reminder->id, 'channel' => $key],
                ['status' => HomeworkReminderChannelStatus::Suppressed, 'resolved_at' => now()],
            );

            if ($delivery->wasRecentlyCreated) {
                return;
            }

            // Never regress an already-Dispatched channel just because
            // the setting was disabled afterward — historical delivery stands.
            if (in_array($delivery->status, [HomeworkReminderChannelStatus::Pending, HomeworkReminderChannelStatus::Sending, HomeworkReminderChannelStatus::Failed], true)) {
                $delivery->forceFill(['status' => HomeworkReminderChannelStatus::Suppressed, 'resolved_at' => now()])->save();
            }
        }, attempts: 3);
    }

    private function attempt(HomeworkDueReminder $reminder, HomeworkAssignment $assignment, string $key, string $laravelChannel): void
    {
        $claimed = DB::transaction(function () use ($reminder, $key): ?HomeworkReminderChannelDelivery {
            $delivery = HomeworkReminderChannelDelivery::query()
                ->where('homework_due_reminder_id', $reminder->id)
                ->where('channel', $key)
                ->lockForUpdate()
                ->first();

            if ($delivery === null) {
                $delivery = HomeworkReminderChannelDelivery::query()->create([
                    'homework_due_reminder_id' => $reminder->id,
                    'channel' => $key,
                    'status' => HomeworkReminderChannelStatus::Pending,
                    'attempts' => 0,
                ]);
            }

            if (in_array($delivery->status, [HomeworkReminderChannelStatus::Dispatched, HomeworkReminderChannelStatus::Suppressed], true)) {
                return null; // already resolved successfully — never resend
            }

            if ($delivery->status === HomeworkReminderChannelStatus::Sending
                && $delivery->updated_at?->gt(now()->subSeconds(self::LEASE_SECONDS))) {
                return null; // another worker actively owns this channel right now
            }

            if ($delivery->status === HomeworkReminderChannelStatus::Failed && $delivery->attempts >= self::MAX_ATTEMPTS) {
                return null; // permanently failed — no retry remains
            }

            $delivery->forceFill([
                'status' => HomeworkReminderChannelStatus::Sending,
                'attempts' => $delivery->attempts + 1,
            ])->save();

            return $delivery;
        }, attempts: 3);

        if ($claimed === null) {
            return;
        }

        // Never hold a transaction open during network delivery.
        try {
            $notification = new HomeworkDueReminderNotification($assignment);

            if ($key === 'database') {
                // Stable across retries: a lease race that somehow lets
                // two workers both reach this point collides on the
                // notifications table's own primary key instead of
                // inserting a duplicate row.
                $notification->id = Uuid::uuid5(Uuid::NAMESPACE_URL, "homework-due-reminder:{$reminder->id}:database")->toString();
            }

            Notification::sendNow($assignment->student, $notification, [$laravelChannel]);
        } catch (UniqueConstraintViolationException) {
            // The deterministic database-notification id already exists
            // — this channel was already delivered; treat as success.
            $this->resolveSuccess($claimed);

            return;
        } catch (Throwable $e) {
            report($e);
            $this->resolveFailure($claimed, $key);

            return;
        }

        $this->resolveSuccess($claimed);

        $this->audit->logSystem(
            'homework',
            'due_reminder_channel_dispatched',
            'Homework due-date reminder channel dispatched.',
            $reminder->assignment,
            ['reminder_id' => $reminder->id, 'channel' => $key, 'attempts' => $claimed->attempts],
        );
    }

    private function resolveSuccess(HomeworkReminderChannelDelivery $delivery): void
    {
        DB::transaction(function () use ($delivery): void {
            $fresh = HomeworkReminderChannelDelivery::query()->whereKey($delivery->id)->lockForUpdate()->first();

            if ($fresh === null || $fresh->status === HomeworkReminderChannelStatus::Dispatched) {
                return;
            }

            $fresh->forceFill(['status' => HomeworkReminderChannelStatus::Dispatched, 'resolved_at' => now()])->save();
        });
    }

    private function resolveFailure(HomeworkReminderChannelDelivery $delivery, string $key): void
    {
        DB::transaction(function () use ($delivery): void {
            $fresh = HomeworkReminderChannelDelivery::query()->whereKey($delivery->id)->lockForUpdate()->first();

            if ($fresh === null) {
                return;
            }

            $exhausted = $fresh->attempts >= self::MAX_ATTEMPTS;

            $fresh->forceFill([
                'status' => $exhausted ? HomeworkReminderChannelStatus::Failed : HomeworkReminderChannelStatus::Pending,
                'failure_category' => 'transient_transport_error',
                'resolved_at' => $exhausted ? now() : null,
            ])->save();
        });

        $this->audit->logSystem(
            'homework',
            'due_reminder_channel_failed',
            'Homework due-date reminder channel failed.',
            null,
            ['reminder_id' => $delivery->homework_due_reminder_id, 'channel' => $key, 'attempts' => $delivery->attempts],
        );
    }
}
