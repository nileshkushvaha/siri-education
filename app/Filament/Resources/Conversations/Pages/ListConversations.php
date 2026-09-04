<?php

declare(strict_types=1);

namespace App\Filament\Resources\Conversations\Pages;

use App\Filament\Resources\Conversations\ConversationResource;
use App\Filament\Support\Tables\StatusTabs;
use App\Filament\Widgets\Messaging\MessagingStatsWidget;
use App\Messaging\Enums\ConversationStatus;
use App\Models\Conversation;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListConversations extends ListRecords
{
    protected static string $resource = ConversationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            MessagingStatsWidget::class,
        ];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return StatusTabs::forEnum(Conversation::class, ConversationStatus::class);
    }
}
