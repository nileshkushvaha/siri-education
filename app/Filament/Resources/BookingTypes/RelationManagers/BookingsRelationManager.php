<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookingTypes\RelationManagers;

use App\Booking\Enums\BookingStatus;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BookingsRelationManager extends RelationManager
{
    protected static string $relationship = 'bookings';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedCalendarDays;

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->searchable()
                    ->fontFamily('mono'),
                TextColumn::make('instructor.name')
                    ->label('Instructor'),
                TextColumn::make('student.name')
                    ->label('Student'),
                TextColumn::make('starts_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (BookingStatus $state): string => $state->label())
                    ->color(fn (BookingStatus $state): string => $state->color()),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(BookingStatus::cases())
                        ->mapWithKeys(fn (BookingStatus $s) => [$s->value => $s->label()])
                        ->toArray()),
            ])
            ->headerActions([])
            ->recordActions([])
            ->defaultSort('starts_at', 'desc');
    }
}
