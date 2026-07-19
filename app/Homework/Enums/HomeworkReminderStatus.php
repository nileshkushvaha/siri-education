<?php

declare(strict_types=1);

namespace App\Homework\Enums;

/**
 * Phase 24K — GAP-020: bounded lifecycle for a claimed due-date
 * reminder. Pending = claimed by the scheduler, not yet resolved;
 * Dispatched = handed to at least one live notification channel;
 * Skipped = revalidation found the reminder no longer useful (stale
 * due date, completed homework, ineligible student, all channels
 * disabled); Failed = a genuine, operationally visible send failure.
 */
enum HomeworkReminderStatus: string
{
    case Pending = 'pending';
    case Dispatched = 'dispatched';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
