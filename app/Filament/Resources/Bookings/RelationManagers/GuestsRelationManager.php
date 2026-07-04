<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bookings\RelationManagers;

use App\Booking\Enums\BookingGuestStatus;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GuestsRelationManager extends RelationManager
{
    protected static string $relationship = 'guests';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedUserGroup;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->maxLength(255),
            TextInput::make('email')->email()->maxLength(150),
            Select::make('status')
                ->options(collect(BookingGuestStatus::cases())
                    ->mapWithKeys(fn (BookingGuestStatus $s) => [$s->value => $s->label()])
                    ->toArray())
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->state(fn ($record): string => $record->user?->name ?? $record->name ?? '—'),
                TextColumn::make('email')
                    ->state(fn ($record): string => $record->user?->email ?? $record->email ?? '—'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (BookingGuestStatus $state): string => $state->label())
                    ->color(fn (BookingGuestStatus $state): string => $state->color()),
                TextColumn::make('responded_at')
                    ->dateTime()
                    ->placeholder('No response'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
