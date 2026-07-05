<?php

namespace App\Filament\Resources\Countries\Schemas;

use App\Models\Currency;
use App\Models\Language;
use DateTimeZone;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CountryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([

                Section::make('Core Information')
                    ->description('Primary identifiers for this country.')
                    ->icon('heroicon-o-globe-alt')
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('name')
                                ->label('Country Name')
                                ->required()
                                ->maxLength(100)
                                ->placeholder('e.g. India')
                                ->helperText('Official country name.'),

                            TextInput::make('iso2')
                                ->label('ISO 2 Code')
                                ->required()
                                ->maxLength(2)
                                ->minLength(2)
                                ->unique(ignoreRecord: true)
                                // ->uppercase()
                                ->placeholder('IN')
                                ->helperText('2-letter ISO 3166-1 alpha-2 code.'),

                            TextInput::make('iso3')
                                ->label('ISO 3 Code')
                                ->maxLength(3)
                                ->minLength(3)
                                ->unique(ignoreRecord: true)
                                // ->uppercase()
                                ->nullable()
                                ->placeholder('IND')
                                ->helperText('3-letter ISO 3166-1 alpha-3 code (optional).'),
                        ]),

                        Grid::make(3)->schema([
                            TextInput::make('phone_code')
                                ->label('Phone Code')
                                ->maxLength(20)
                                ->nullable()
                                ->placeholder('+91')
                                ->helperText('International dialling prefix.'),

                            TextInput::make('nationality')
                                ->label('Nationality')
                                ->maxLength(100)
                                ->nullable()
                                ->placeholder('Indian')
                                ->helperText('Demonym for this country.'),

                            TextInput::make('flag')
                                ->label('Flag Emoji')
                                ->maxLength(10)
                                ->nullable()
                                ->placeholder('🇮🇳')
                                ->helperText('Paste the flag emoji directly.'),
                        ]),
                    ])
                    ->columnSpanFull(),

                Section::make('Localization Defaults')
                    ->description('Country-aware defaults for currency, timezone, language, and formatting.')
                    ->icon('heroicon-o-language')
                    ->schema([
                        Grid::make(3)->schema([
                            Select::make('default_currency_id')
                                ->label('Default Currency')
                                ->options(fn () => Currency::query()->active()->orderBy('code')->pluck('code', 'id'))
                                ->searchable()
                                ->preload()
                                ->nullable()
                                ->native(false),

                            Select::make('default_language_id')
                                ->label('Default Language')
                                ->options(fn () => Language::query()->active()->orderBy('name')->pluck('name', 'id'))
                                ->searchable()
                                ->preload()
                                ->nullable()
                                ->native(false),

                            Select::make('default_timezone')
                                ->label('Default Timezone')
                                ->options(fn () => collect(DateTimeZone::listIdentifiers())->mapWithKeys(fn (string $timezone): array => [$timezone => $timezone])->all())
                                ->searchable()
                                ->nullable()
                                ->native(false),
                        ]),

                        Grid::make(3)->schema([
                            TextInput::make('date_format')
                                ->maxLength(40)
                                ->nullable()
                                ->placeholder('Y-m-d'),

                            TextInput::make('time_format')
                                ->maxLength(40)
                                ->nullable()
                                ->placeholder('H:i'),

                            TextInput::make('number_format')
                                ->maxLength(40)
                                ->nullable()
                                ->placeholder('1,234.56'),
                        ]),
                    ])
                    ->columnSpanFull(),

                Section::make('Support')
                    ->description('Country-specific support contact overrides.')
                    ->icon('heroicon-o-lifebuoy')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('support_email')
                                ->email()
                                ->maxLength(255)
                                ->nullable()
                                ->placeholder('support@example.com'),

                            TextInput::make('support_phone')
                                ->maxLength(50)
                                ->nullable()
                                ->placeholder('+91 00000 00000'),
                        ]),
                    ])
                    ->columnSpanFull(),

                Section::make('Settings & Meta')
                    ->description('Display order, visibility, and internal notes.')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('sort_order')
                                ->label('Sort Order')
                                ->integer()
                                ->default(0)
                                ->minValue(0)
                                ->helperText('Lower value appears first in lists.'),

                            Select::make('status')
                                ->label('Status')
                                ->options([
                                    'active' => 'Active',
                                    'inactive' => 'Inactive',
                                ])
                                ->default('active')
                                ->required()
                                ->native(false)
                                ->helperText('Only active countries appear in front-end dropdowns.'),
                        ]),

                        Textarea::make('remarks')
                            ->label('Remarks')
                            ->maxLength(500)
                            ->nullable()
                            ->rows(3)
                            ->placeholder('Internal notes about this country (optional).')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
