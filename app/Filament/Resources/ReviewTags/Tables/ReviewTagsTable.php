<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReviewTags\Tables;

use App\Models\ReviewTag;
use App\Reviews\Enums\LessonReviewEligibilityMode;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

/**
 * No delete/force-delete action anywhere — ReviewTag has no
 * `deleted_at` column at all; retirement is
 * `is_active` only, via EditAction or the activate/deactivate bulk
 * actions below (mirrors BookingTypesTable's is_active pattern).
 */
class ReviewTagsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('key')
                    ->badge()
                    ->color('gray')
                    ->searchable(),
                TextColumn::make('applicable_modes')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => LessonReviewEligibilityMode::from($state)->label()),
                IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active'),
                TextColumn::make('sort_order')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
                SelectFilter::make('applicable_modes')
                    ->options(collect(LessonReviewEligibilityMode::cases())
                        ->filter(fn (LessonReviewEligibilityMode $mode) => $mode !== LessonReviewEligibilityMode::Disabled)
                        ->mapWithKeys(fn (LessonReviewEligibilityMode $mode) => [$mode->value => $mode->label()])
                        ->toArray())
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereJsonContains('applicable_modes', $data['value'])
                        : $query),
            ])
            ->recordActions([
                EditAction::make()->authorize('update'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('activate')
                        ->icon('heroicon-m-check-circle')
                        ->color('success')
                        ->authorize(fn (): bool => auth()->user()?->can('update', ReviewTag::class) ?? false)
                        ->action(function (Collection $records): void {
                            $records->each->update(['is_active' => true]);
                            Notification::make()->title('Review tags activated')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('deactivate')
                        ->icon('heroicon-m-no-symbol')
                        ->color('warning')
                        ->authorize(fn (): bool => auth()->user()?->can('update', ReviewTag::class) ?? false)
                        ->action(function (Collection $records): void {
                            $records->each->update(['is_active' => false]);
                            Notification::make()->title('Review tags deactivated')->warning()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('sort_order');
    }
}
