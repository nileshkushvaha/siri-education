<?php

declare(strict_types=1);

namespace App\Filament\Resources\Conversations;

use App\Filament\Resources\Conversations\Pages\ListConversations;
use App\Filament\Resources\Conversations\Pages\ViewConversation;
use App\Filament\Resources\Conversations\RelationManagers\MessageReportsRelationManager;
use App\Filament\Resources\Conversations\RelationManagers\MessagesRelationManager;
use App\Filament\Resources\Conversations\Schemas\ConversationInfolist;
use App\Filament\Resources\Conversations\Tables\ConversationsTable;
use App\Messaging\Enums\MessageReportStatus;
use App\Models\Conversation;
use App\Models\MessageReport;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * GAP-017 / SRS §17.36 staff oversight surface. Conversations are
 * opened only through MessagingService (from the student/instructor
 * dashboard) — there is intentionally no Create page. Every action
 * delegates to MessagingService; no business logic here.
 */
class ConversationResource extends Resource
{
    protected static ?string $model = Conversation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|\UnitEnum|null $navigationGroup = 'Support';

    protected static ?string $recordTitleAttribute = 'id';

    public static function infolist(Schema $schema): Schema
    {
        return ConversationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ConversationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            MessagesRelationManager::class,
            MessageReportsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConversations::route('/'),
            'view' => ViewConversation::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['student', 'instructor']);
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = MessageReport::query()->where('status', MessageReportStatus::Pending)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', Conversation::class) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view', $record) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
