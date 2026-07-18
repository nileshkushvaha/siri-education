<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorDocumentRequirements\Tables;

use App\Enums\InstructorEvidenceCollection;
use App\Models\InstructorDocumentRequirement;
use App\Models\User;
use App\Services\Instructor\InstructorDocumentRequirementService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class InstructorDocumentRequirementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('collection_name')
                    ->label('Evidence Type')
                    ->formatStateUsing(fn (string $state): string => InstructorEvidenceCollection::tryFrom($state)?->label() ?? str($state)->replace('_', ' ')->headline()->toString())
                    ->description(fn (string $state): string => $state)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('label')
                    ->searchable(),
                IconColumn::make('required')
                    ->boolean(),
                IconColumn::make('active')
                    ->boolean(),
                TextColumn::make('max_size_kb')
                    ->label('Max Size (KB)')
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label('Order')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('active'),
                TernaryFilter::make('required'),
            ])
            ->recordActions([
                EditAction::make(),
                self::toggleActiveAction(),
            ])
            ->bulkActions([])
            ->defaultSort('sort_order');
    }

    private static function toggleActiveAction(): Action
    {
        return Action::make('toggleActive')
            ->label(fn (InstructorDocumentRequirement $record): string => $record->active ? 'Deactivate' : 'Activate')
            ->icon(fn (InstructorDocumentRequirement $record): string => $record->active ? 'heroicon-m-eye-slash' : 'heroicon-m-eye')
            ->color(fn (InstructorDocumentRequirement $record): string => $record->active ? 'danger' : 'success')
            ->requiresConfirmation()
            ->action(function (InstructorDocumentRequirement $record): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                app(InstructorDocumentRequirementService::class)->update($actor, $record, ['active' => ! $record->active]);

                Notification::make()
                    ->title($record->fresh()->active ? 'Requirement activated' : 'Requirement deactivated')
                    ->success()
                    ->send();
            });
    }
}
