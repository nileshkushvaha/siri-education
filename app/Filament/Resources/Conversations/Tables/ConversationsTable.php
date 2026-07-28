<?php

declare(strict_types=1);

namespace App\Filament\Resources\Conversations\Tables;

use App\Messaging\Enums\ConversationStatus;
use App\Messaging\Exceptions\MessagingException;
use App\Messaging\Services\MessagingService;
use App\Models\Conversation;
use App\Models\MessagingRestriction;
use App\Support\ModelDisplayName;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Every lifecycle mutation routes through MessagingService. */
class ConversationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('student.name')
                    ->label('Student')
                    ->searchable(),
                TextColumn::make('instructor.name')
                    ->label('Instructor')
                    ->searchable(),
                TextColumn::make('context_type')
                    ->label('Context')
                    ->formatStateUsing(fn (string $state): string => ModelDisplayName::for($state)),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ConversationStatus $state): string => $state->label())
                    ->color(fn (ConversationStatus $state): string => $state->color())
                    ->sortable(),
                TextColumn::make('messages_count')
                    ->label('Messages')
                    ->counts('messages')
                    ->sortable(),
                TextColumn::make('last_message_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(ConversationStatus::cases())->mapWithKeys(fn (ConversationStatus $s) => [$s->value => $s->label()])),
            ])
            ->recordActions([
                ViewAction::make(),

                Action::make('restrict_participant')
                    ->label('Restrict Participant')
                    ->icon('heroicon-m-no-symbol')
                    ->color('danger')
                    ->authorize(fn (): bool => auth()->user()?->can('viewAny', Conversation::class) ?? false)
                    ->form(fn (Conversation $record): array => [
                        Select::make('participant')
                            ->options(array_filter([
                                (string) $record->student_id => $record->student->name.' (student)',
                                (string) $record->instructor_id => $record->instructor->name.' (instructor)',
                            ]))
                            ->required()
                            ->native(false),
                        Textarea::make('reason')->required()->maxLength(500),
                    ])
                    ->action(function (Conversation $record, array $data): void {
                        try {
                            $target = $data['participant'] === (string) $record->student_id ? $record->student : $record->instructor;
                            app(MessagingService::class)->applyRestriction($target, auth()->user(), $data['reason']);
                            Notification::make()->title('Messaging restricted')->success()->send();
                        } catch (MessagingException $e) {
                            Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Action::make('unrestrict_participant')
                    ->label('Remove Restriction')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->authorize(fn (): bool => auth()->user()?->can('viewAny', Conversation::class) ?? false)
                    ->visible(fn (Conversation $record): bool => MessagingRestriction::query()
                        ->whereIn('user_id', [$record->student_id, $record->instructor_id])
                        ->whereNull('lifted_at')
                        ->exists())
                    ->form(fn (Conversation $record): array => [
                        Select::make('restriction_id')
                            ->label('Restriction to lift')
                            ->options(MessagingRestriction::query()
                                ->whereIn('user_id', [$record->student_id, $record->instructor_id])
                                ->whereNull('lifted_at')
                                ->get()
                                ->mapWithKeys(fn (MessagingRestriction $r) => [$r->id => $r->user->name]))
                            ->required()
                            ->native(false),
                        Textarea::make('reason')->required()->maxLength(500),
                    ])
                    ->action(function (array $data): void {
                        try {
                            $restriction = MessagingRestriction::query()->findOrFail($data['restriction_id']);
                            app(MessagingService::class)->removeRestriction($restriction, auth()->user(), $data['reason']);
                            Notification::make()->title('Restriction removed')->success()->send();
                        } catch (MessagingException $e) {
                            Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Action::make('close')
                    ->icon('heroicon-m-x-circle')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->authorize(fn (Conversation $record): bool => auth()->user()?->can('close', $record) ?? false)
                    ->visible(fn (Conversation $record): bool => $record->status !== ConversationStatus::Closed)
                    ->action(function (Conversation $record): void {
                        app(MessagingService::class)->close($record, auth()->user());
                        Notification::make()->title('Conversation closed')->success()->send();
                    }),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('messages'))
            ->defaultSort('last_message_at', 'desc');
    }
}
