<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookingTypes\Schemas;

use App\Booking\Registry\BookingTypeRegistry;
use Filament\Forms\Components\Placeholder;
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
                    ->description('The system type determines the underlying behavior for this booking type. The fields on this page only control its name, duration, price, and other settings.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('key')
                                ->label('System type')
                                // Only registered drivers are bookable, so only they may be created.
                                ->options(app(BookingTypeRegistry::class)->options())
                                ->required()
                                ->unique(ignoreRecord: true)
                                ->disabledOn('edit')
                                ->dehydratedWhenHidden()
                                ->placeholder('Select a type')
                                ->helperText('Only types already built into the platform can be selected here. Contact engineering to add a new one.'),
                            TextInput::make('name')
                                ->required()
                                ->maxLength(255)
                                ->placeholder('e.g. Paid 1-to-1 Session'),
                        ]),
                        Textarea::make('description')
                            ->rows(2)
                            ->maxLength(1000)
                            ->placeholder('Brief description of this booking type…')
                            ->columnSpanFull(),
                    ]),

                Section::make('Scheduling')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('duration_minutes')
                                ->numeric()
                                ->required()
                                ->minValue(5)
                                ->maxValue(600)
                                ->suffix('min')
                                ->placeholder('e.g. 60'),
                            TextInput::make('buffer_minutes')
                                ->numeric()
                                ->default(0)
                                ->minValue(0)
                                ->maxValue(120)
                                ->suffix('min')
                                ->placeholder('0')
                                ->helperText('Gap enforced before and after each booking.'),
                        ]),
                        Toggle::make('requires_approval')
                            ->helperText('When off, bookings auto-confirm.'),
                    ]),

                Section::make('Behavior & visibility')
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('is_paid')
                            ->label('Paid type')
                            ->helperText('Whether this type requires payment at all — not what it costs.'),
                        Placeholder::make('pricing_matrix_notice')
                            ->label('')
                            ->content('Student-facing paid prices are managed from Student Lesson Prices.')
                            ->visible(fn (Get $get): bool => (bool) $get('is_paid')),
                        Grid::make(2)->schema([
                            Toggle::make('is_active')
                                ->default(true)
                                ->helperText('Inactive types stop accepting bookings immediately.'),
                            TextInput::make('sort_order')
                                ->numeric()
                                ->default(0)
                                ->placeholder('0'),
                        ]),
                    ]),
            ]);
    }
}
