<?php

namespace App\Filament\Resources\Academic\Tables;

use App\Filament\Support\Tables\AdminListTable;
use App\Models\InstructorSubjectTopic;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class InstructorSubjectTopicsTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['teacher', 'topic.subject', 'academicLevel', 'approver']))
            ->columns([
                TextColumn::make('teacher.name')
                    ->label('Instructor')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),
                TextColumn::make('topic.subject.name')
                    ->label('Subject')
                    ->sortable(),
                TextColumn::make('topic.name')
                    ->label('Topic')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('academicLevel.name')
                    ->label('Level')
                    ->placeholder('All levels')
                    ->sortable(),
                TextColumn::make('approval')
                    ->label('Approval')
                    ->badge()
                    ->state(fn (InstructorSubjectTopic $record): string => $record->approved_at !== null ? 'Approved' : 'Pending')
                    ->color(fn (string $state): string => $state === 'Approved' ? 'success' : 'warning')
                    ->tooltip(fn (InstructorSubjectTopic $record): ?string => $record->approved_at
                        ? 'Approved '.$record->approved_at->diffForHumans().($record->approver?->name ? ' by '.$record->approver->name : '')
                        : null),
                ToggleColumn::make('is_active')
                    ->label('Active')
                    ->disabled(fn (InstructorSubjectTopic $record): bool => ! (auth()->user()?->can('update', $record) ?? false)),
                TextColumn::make('updated_at')
                    ->label('Last updated')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('teacher_id')
                    ->label('Instructor')
                    ->relationship('teacher', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('subject_id')
                    ->label('Subject')
                    ->relationship('subject', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('subject_topic_id')
                    ->label('Topic')
                    ->relationship('topic', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('academic_level_id')
                    ->label('Level')
                    ->relationship('academicLevel', 'name')
                    ->searchable()
                    ->preload(),
                TernaryFilter::make('approved')
                    ->label('Approval')
                    ->trueLabel('Approved')
                    ->falseLabel('Pending approval')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('approved_at'),
                        false: fn (Builder $query) => $query->whereNull('approved_at'),
                    ),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->groups([
                Group::make('teacher.name')->label('Instructor')->collapsible(),
                Group::make('topic.subject.name')->label('Subject')->collapsible(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('approve')
                        ->label('Approve')
                        ->icon(Heroicon::OutlinedCheckBadge)
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $records->each(fn (InstructorSubjectTopic $coverage) => $coverage
                                ->forceFill(['approved_at' => now(), 'approved_by' => auth()->id()])
                                ->save());
                            Notification::make()->title('Coverage approved')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('activate')
                        ->label('Activate')
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $records->each->update(['is_active' => true]);
                            Notification::make()->title('Coverage activated')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('deactivate')
                        ->label('Deactivate')
                        ->icon(Heroicon::OutlinedXCircle)
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $records->each->update(['is_active' => false]);
                            Notification::make()->title('Coverage deactivated')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No topic coverage yet')
            ->emptyStateDescription('Coverage records which topics each instructor teaches. Instructors declare them; you approve them here.')
            ->defaultSort('updated_at', 'desc');

        return AdminListTable::apply($table, 'Search instructor or topic');
    }
}
