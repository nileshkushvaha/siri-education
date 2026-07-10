<?php

namespace App\Filament\Resources\Academic\Tables;

use App\Models\InstructorSubjectTopic;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class InstructorSubjectTopicsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['teacher', 'topic.subject', 'academicLevel']))
            ->columns([
                TextColumn::make('teacher.name')
                    ->label('Instructor')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('topic.subject.name')
                    ->label('Subject'),
                TextColumn::make('topic.name')
                    ->label('Topic')
                    ->searchable(),
                TextColumn::make('academicLevel.name')
                    ->label('Level')
                    ->placeholder('All levels'),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                IconColumn::make('approved_at')
                    ->label('Approved')
                    ->boolean()
                    ->state(fn (InstructorSubjectTopic $record): bool => $record->approved_at !== null),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('subject_topic_id')
                    ->label('Topic')
                    ->relationship('topic', 'name')
                    ->searchable()
                    ->preload(),
                TernaryFilter::make('is_active')->label('Active'),
                TernaryFilter::make('approved')
                    ->label('Approved')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('approved_at'),
                        false: fn ($query) => $query->whereNull('approved_at'),
                    ),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('approve')
                        ->label('Approve selected')
                        ->icon('heroicon-m-check-badge')
                        ->action(function (Collection $records): void {
                            $records->each(fn (InstructorSubjectTopic $coverage) => $coverage
                                ->forceFill(['approved_at' => now(), 'approved_by' => auth()->id()])
                                ->save());
                            Notification::make()->title('Coverage approved')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('updated_at', 'desc');
    }
}
