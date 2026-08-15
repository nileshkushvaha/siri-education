<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\RelationManagers;

use App\Models\Activity;
use App\Support\ActivityLogColors;
use App\Support\Timezone\ViewerDateTime;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Read-only — this user's own activity log entries (as causer). Reuses the
 * same column patterns as ActivityLogTable, scoped to a single user so the
 * actor/causer filter columns from that table aren't needed here.
 */
class ActivityLogRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $title = 'Activity';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedClock;

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('log_name')
                    ->label('Log')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('event')
                    ->label('Event')
                    ->badge()
                    ->color(fn (?string $state): string => ActivityLogColors::forEvent($state)),

                TextColumn::make('description')
                    ->label('Description')
                    ->limit(60)
                    ->tooltip(fn (Activity $record): string => $record->description),

                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('M j, Y H:i')
                    ->sortable()
                    ->since()
                    ->tooltip(fn (Activity $record): string => ViewerDateTime::labelled($record->created_at, format: 'Y-m-d H:i:s')),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label('Event')
                    ->options(fn (): array => Activity::query()
                        ->where('causer_type', $this->getOwnerRecord()::class)
                        ->where('causer_id', $this->getOwnerRecord()->getKey())
                        ->distinct()
                        ->whereNotNull('event')
                        ->pluck('event', 'event')
                        ->mapWithKeys(fn ($v) => [$v => ucwords(str_replace('_', ' ', $v))])
                        ->toArray()
                    )
                    ->placeholder('All events'),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->paginated([10, 25, 50])
            ->emptyStateHeading('No activity yet')
            ->emptyStateDescription('Actions this user takes will appear here.');
    }
}
