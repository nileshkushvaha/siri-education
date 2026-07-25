<?php

declare(strict_types=1);

namespace App\Filament\Resources\Conversations\RelationManagers;

use App\Messaging\Enums\MessageReportReason;
use App\Messaging\Enums\MessageReportStatus;
use App\Messaging\Services\MessagingService;
use App\Models\Conversation;
use App\Models\MessageReport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * SRS §17.35-§17.36: view + review reported messages. Every review
 * delegates to MessagingService::reviewReport() — no direct write.
 */
class MessageReportsRelationManager extends RelationManager
{
    protected static string $relationship = 'messageReports';

    protected static ?string $title = 'Reported Messages';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedFlag;

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Reported')->dateTime()->sortable(),
                TextColumn::make('message.body')->label('Message')->wrap()->limit(200),
                TextColumn::make('reporter.name')->label('Reported by'),
                TextColumn::make('reason')
                    ->badge()
                    ->formatStateUsing(fn (MessageReportReason $state): string => $state->label()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (MessageReportStatus $state): string => $state->label())
                    ->color(fn (MessageReportStatus $state): string => $state->color()),
            ])
            ->headerActions([])
            ->recordActions([
                Action::make('review')
                    ->icon('heroicon-m-eye')
                    ->color('info')
                    ->authorize(fn (): bool => auth()->user()?->can('viewAny', Conversation::class) ?? false)
                    ->form([
                        Select::make('status')
                            ->options(collect(MessageReportStatus::cases())->mapWithKeys(fn (MessageReportStatus $s) => [$s->value => $s->label()]))
                            ->required()
                            ->native(false),
                        Textarea::make('notes')->label('Review notes')->maxLength(1000),
                    ])
                    ->fillForm(fn (MessageReport $record): array => [
                        'status' => $record->status->value,
                        'notes' => $record->review_notes,
                    ])
                    ->action(function (MessageReport $record, array $data): void {
                        app(MessagingService::class)->reviewReport($record, auth()->user(), MessageReportStatus::from($data['status']), $data['notes'] ?? null);
                        Notification::make()->title('Report reviewed')->success()->send();
                    }),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }
}
