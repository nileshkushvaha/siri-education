<?php

declare(strict_types=1);

namespace App\Homework\Enums;

/**
 * Partial-channel idempotency: bounded lifecycle
 * for one (reminder, channel) delivery. Sending is a short-lived lease
 * state, not a terminal one — a crashed worker leaves a channel here,
 * and it is reclaimed once the lease (HomeworkReminderChannelSender::LEASE_SECONDS)
 * expires, rather than stuck forever. Suppressed is terminal and never
 * retried (channel disabled by settings). Failed is terminal only once
 * the channel's own attempt budget is exhausted; before that, a failed
 * attempt reverts to Pending so it can be retried.
 */
enum HomeworkReminderChannelStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Dispatched = 'dispatched';
    case Suppressed = 'suppressed';
    case Failed = 'failed';
}
