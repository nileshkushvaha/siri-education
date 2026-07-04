<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookingTypes\Schemas;

use App\Booking\Registry\BookingTypeRegistry;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class BookingTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Type')
                    ->description('The key links this row to its code driver — rows hold tunable values, drivers hold behavior.')
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('key')
                                ->label('Driver key')
                                // Only registered drivers are bookable, so only they may be created.
                                ->options(app(BookingTypeRegistry::class)->options())
                                ->required()
                                ->unique(ignoreRecord: true)
                                ->disabledOn('edit')
                                ->dehydratedWhenHidden()
                                ->helperText('New keys require a BookingTypeInterface driver (see docs/booking.md).'),
                            TextInput::make('name')
                                ->required()
                                ->maxLength(255),
                        ]),
                        Textarea::make('description')
                            ->rows(2)
                            ->maxLength(1000),
                    ]),

                Section::make('Scheduling')
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('duration_minutes')
                                ->numeric()
                                ->required()
                                ->minValue(5)
                                ->maxValue(600)
                                ->suffix('min'),
                            TextInput::make('buffer_minutes')
                                ->numeric()
                                ->default(0)
                                ->minValue(0)
                                ->maxValue(120)
                                ->suffix('min')
                                ->helperText('Gap enforced before and after each booking.'),
                            TextInput::make('max_attendees')
                                ->numeric()
                                ->minValue(1)
                                ->nullable()
                                ->helperText('Leave empty for unlimited (webinars).'),
                        ]),
                        Toggle::make('requires_approval')
                            ->helperText('When off, bookings auto-confirm.'),
                    ]),

                Section::make('Pricing & visibility')
                    ->schema([
                        Grid::make(3)->schema([
                            Toggle::make('is_paid')
                                ->live()
                                ->label('Paid type'),
                            TextInput::make('price')
                                ->numeric()
                                ->minValue(0)
                                ->visible(fn (Get $get): bool => (bool) $get('is_paid'))
                                ->requiredIfAccepted('is_paid'),
                            TextInput::make('currency')
                                ->length(3)
                                ->placeholder('USD')
                                ->visible(fn (Get $get): bool => (bool) $get('is_paid')),
                        ]),
                        Grid::make(2)->schema([
                            Toggle::make('is_active')
                                ->default(true)
                                ->helperText('Inactive types stop accepting bookings immediately.'),
                            TextInput::make('sort_order')
                                ->numeric()
                                ->default(0),
                        ]),
                    ]),
            ]);
    }
}
