<?php

namespace App\Filament\Resources\Currencies\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CurrencyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Currency Details')
                ->description('ISO 4217 currency metadata used for country defaults.')
                ->icon('heroicon-o-banknotes')
                ->schema([
                    Grid::make(3)->schema([
                        TextInput::make('code')
                            ->label('ISO Code')
                            ->required()
                            ->minLength(3)
                            ->maxLength(3)
                            ->unique(ignoreRecord: true)
                            ->placeholder('USD'),

                        TextInput::make('name')
                            ->required()
                            ->maxLength(100)
                            ->placeholder('US Dollar'),

                        TextInput::make('symbol')
                            ->maxLength(10)
                            ->nullable()
                            ->placeholder('$'),
                    ]),

                    Grid::make(3)->schema([
                        TextInput::make('numeric_code')
                            ->label('Numeric Code')
                            ->minLength(3)
                            ->maxLength(3)
                            ->regex('/^\d{3}$/')
                            ->unique(ignoreRecord: true)
                            ->nullable()
                            ->placeholder('840')
                            ->helperText('ISO 4217 numeric code, e.g. 840. Leading zeros are significant (e.g. 008) — kept as text, not a number field.'),

                        TextInput::make('minor_units')
                            ->label('Minor Units')
                            ->integer()
                            ->minValue(0)
                            ->maxValue(4)
                            ->default(2)
                            ->required(),

                        Select::make('status')
                            ->options([
                                'active' => 'Active',
                                'inactive' => 'Inactive',
                            ])
                            ->default('active')
                            ->required()
                            ->native(false),
                    ]),

                    Grid::make(2)->schema([
                        TextInput::make('sort_order')
                            ->integer()
                            ->minValue(0)
                            ->default(0),

                        Textarea::make('remarks')
                            ->maxLength(500)
                            ->nullable()
                            ->rows(2),
                    ]),
                ])
                ->columnSpanFull(),
        ]);
    }
}
