<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Messaging;

use App\Messaging\Services\MessagingReportingService;
use App\Models\Conversation;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** SRS §17.44 minimal messaging reporting — bounded aggregate counts only. */
class MessagingStatsWidget extends BaseWidget
{
    protected static ?int $sort = 0;

    public static function canView(): bool
    {
        return auth()->user()?->can('viewAny', Conversation::class) ?? false;
    }

    protected function getStats(): array
    {
        $reporting = app(MessagingReportingService::class);

        return [
            Stat::make('Conversations', (string) $reporting->totalConversations())
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('info'),

            Stat::make('Messages', (string) $reporting->totalMessages())
                ->description(sprintf('%d flagged by leakage policy', $reporting->flaggedMessageCount()))
                ->icon('heroicon-o-envelope')
                ->color($reporting->flaggedMessageCount() > 0 ? 'warning' : 'success'),

            Stat::make('Reported messages', (string) $reporting->totalReportCount())
                ->description(sprintf('%d pending review', $reporting->pendingReportCount()))
                ->icon('heroicon-o-flag')
                ->color($reporting->pendingReportCount() > 0 ? 'danger' : 'success'),

            Stat::make('Active restrictions', (string) $reporting->activeRestrictionCount())
                ->icon('heroicon-o-no-symbol')
                ->color($reporting->activeRestrictionCount() > 0 ? 'warning' : 'success'),
        ];
    }
}
