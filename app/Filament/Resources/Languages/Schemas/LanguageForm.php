<?php

namespace App\Filament\Resources\Languages\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LanguageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Language Details')
                ->description('Language metadata used for country defaults and locale-aware UI.')
                ->icon('heroicon-o-language')
                ->schema([
                    Grid::make(3)->schema([
                        TextInput::make('code')
                            ->label('Language Tag')
                            ->required()
                            ->maxLength(10)
                            ->unique(ignoreRecord: true)
                            ->placeholder('en'),

                        TextInput::make('name')
                            ->required()
                            ->maxLength(100)
                            ->placeholder('English'),

                        TextInput::make('native_name')
                            ->label('Native Name')
                            ->maxLength(100)
                            ->nullable()
                            ->placeholder('English'),
                    ]),

                    Grid::make(3)->schema([
                        Select::make('direction')
                            ->options([
                                'ltr' => 'Left to right',
                                'rtl' => 'Right to left',
                            ])
                            ->default('ltr')
                            ->required()
                            ->native(false),

                        Select::make('status')
                            ->options([
                                'active' => 'Active',
                                'inactive' => 'Inactive',
                            ])
                            ->default('active')
                            ->required()
                            ->native(false),

                        TextInput::make('sort_order')
                            ->integer()
                            ->minValue(0)
                            ->default(0),
                    ]),

                    Textarea::make('remarks')
                        ->maxLength(500)
                        ->nullable()
                        ->rows(2),
                ])
                ->columnSpanFull(),
        ]);
    }
}
