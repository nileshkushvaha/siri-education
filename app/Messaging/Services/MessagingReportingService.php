<?php

declare(strict_types=1);

namespace App\Messaging\Services;

use App\Messaging\Enums\MessageReportStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReport;
use App\Models\MessagingRestriction;

/**
 * Minimal messaging reporting (SRS §17.44 "Notifications and
 * Communication Reports" — Messaging usage / Reported messages /
 * Messaging restrictions applied). Every query here is a bounded
 * aggregate `count()` — never a raw collection materialized and
 * counted in PHP — and is only ever surfaced through a
 * permission-controlled Filament widget.
 */
final class MessagingReportingService
{
    public function totalConversations(): int
    {
        return Conversation::query()->count();
    }

    public function totalMessages(): int
    {
        return Message::query()->count();
    }

    public function flaggedMessageCount(): int
    {
        return Message::query()->where('flagged_leakage', true)->count();
    }

    public function pendingReportCount(): int
    {
        return MessageReport::query()->where('status', MessageReportStatus::Pending)->count();
    }

    public function totalReportCount(): int
    {
        return MessageReport::query()->count();
    }

    public function activeRestrictionCount(): int
    {
        return MessagingRestriction::query()->whereNull('lifted_at')->count();
    }
}
