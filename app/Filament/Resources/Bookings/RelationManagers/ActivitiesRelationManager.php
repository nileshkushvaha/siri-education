<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bookings\RelationManagers;

use App\Booking\Enums\BookingActivityAction;
use App\Booking\Enums\BookingActor;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Read-only lifecycle timeline (booking_activities). */
class ActivitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $title = 'Timeline';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedClock;

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
                TextColumn::make('action')
                    ->badge()
                    ->formatStateUsing(fn (BookingActivityAction $state): string => $state->label()),
                TextColumn::make('actor_type')
                    ->label('By')
                    ->formatStateUsing(fn (BookingActor $state): string => $state->label()),
                TextColumn::make('status_to')
                    ->label('Status')
                    ->formatStateUsing(fn ($state): string => $state?->label() ?? '—'),
                TextColumn::make('meta.reason')
                    ->label('Reason')
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }
}
