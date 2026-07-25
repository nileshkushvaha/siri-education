<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportCases\RelationManagers;

use App\Models\SupportCase;
use App\SupportCases\Enums\SupportCaseReplyVisibility;
use App\SupportCases\Services\SupportCaseService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Immutable reply/internal-note timeline (SRS §25.19-§25.20). Every
 * row is written exclusively via SupportCaseService::addReply() — no
 * edit or delete action exists here, matching the model's own
 * PreventsHardDeletion + PreventsUpdates guards.
 */
class RepliesRelationManager extends RelationManager
{
    protected static string $relationship = 'replies';

    protected static ?string $title = 'Replies & Internal Notes';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedChatBubbleLeftRight;

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('author.name')
                    ->label('Author'),
                TextColumn::make('visibility')
                    ->badge()
                    ->formatStateUsing(fn (SupportCaseReplyVisibility $state): string => $state->label())
                    ->color(fn (SupportCaseReplyVisibility $state): string => $state === SupportCaseReplyVisibility::InternalNote ? 'gray' : 'success'),
                TextColumn::make('body')
                    ->wrap()
                    ->limit(300),
            ])
            ->headerActions([
                Action::make('add_reply')
                    ->label('Reply')
                    ->icon('heroicon-m-chat-bubble-left-right')
                    ->color('primary')
                    ->authorize(fn (): bool => auth()->user()?->can('reply', $this->getOwnerRecord()) ?? false)
                    ->form([
                        Textarea::make('body')->required()->maxLength(4000)->rows(4),
                    ])
                    ->action(fn (array $data) => self::addReply($this->getOwnerRecord(), $data['body'], SupportCaseReplyVisibility::RequesterVisible)),

                Action::make('add_internal_note')
                    ->label('Internal Note')
                    ->icon('heroicon-m-lock-closed')
                    ->color('gray')
                    ->authorize(fn (): bool => auth()->user()?->can('addInternalNote', $this->getOwnerRecord()) ?? false)
                    ->form([
                        Textarea::make('body')->required()->maxLength(4000)->rows(4),
                    ])
                    ->action(fn (array $data) => self::addReply($this->getOwnerRecord(), $data['body'], SupportCaseReplyVisibility::InternalNote)),
            ])
            ->recordActions([])
            ->bulkActions([])
            ->defaultSort('created_at', 'asc');
    }

    private static function addReply(SupportCase $case, string $body, SupportCaseReplyVisibility $visibility): void
    {
        app(SupportCaseService::class)->addReply($case, auth()->user(), $body, $visibility);

        Notification::make()
            ->title($visibility === SupportCaseReplyVisibility::InternalNote ? 'Internal note added' : 'Reply added')
            ->success()
            ->send();
    }
}
