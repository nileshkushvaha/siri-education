<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\NotificationDispatchLog;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Claims a stable idempotency key before a notification listener sends
 * anything. The unique database index — not an in-memory check — is
 * the actual guarantee: a replayed event, two concurrent listener
 * runs, or a recipient reachable through more than one admin
 * permission all converge to exactly one claimed row, so `$send()`
 * runs at most once per key. A failed *delivery* (the queued
 * Notification job itself retrying) never reaches here a second time
 * for the same business event — that retry reuses the same
 * already-dispatched Illuminate Notification job, not a new claim.
 */
final class NotificationIdempotencyGuard
{
    public function once(string $idempotencyKey, string $notificationClass, callable $send): bool
    {
        try {
            NotificationDispatchLog::query()->create([
                'idempotency_key' => $idempotencyKey,
                'notification_class' => $notificationClass,
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false; // already claimed — this IS the idempotent outcome
        }

        $send();

        return true;
    }
}
