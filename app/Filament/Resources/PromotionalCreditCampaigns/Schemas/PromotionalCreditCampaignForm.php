<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromotionalCreditCampaigns\Schemas;

use App\Models\Currency;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Campaign rule form. Status is deliberately absent — lifecycle moves
 * only through the table's service-backed actions. Amount is entered
 * as integer minor units, never a float.
 */
class PromotionalCreditCampaignForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Campaign')
                ->columnSpanFull()
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->placeholder('e.g. Launch Bonus 2026'),
                    Textarea::make('description')
                        ->rows(2)
                        ->maxLength(1000)
                        ->columnSpanFull(),
                    Grid::make(2)->schema([
                        DateTimePicker::make('starts_at')
                            ->label('Starts (UTC)')
                            ->required()
                            ->seconds(false),
                        DateTimePicker::make('ends_at')
                            ->label('Ends (UTC, exclusive)')
                            ->required()
                            ->seconds(false)
                            ->after('starts_at'),
                    ]),
                ]),

            Section::make('Credit rules')
                ->description('Every student in this campaign receives the same fixed credit amount — this cannot be set as a percentage.')
                ->columnSpanFull()
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('amount_minor')
                            ->label('Credit amount (smallest currency unit)')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue(1)
                            ->helperText('Enter the amount in the smallest unit of the selected currency — e.g. paise for INR, cents for USD. For example, 50000 equals 500.00 in that currency\'s main unit.'),
                        Select::make('currency_code')
                            ->label('Currency')
                            ->options(fn (): array => Currency::query()->active()->orderBy('sort_order')->pluck('code', 'code')->toArray())
                            ->required()
                            ->native(false),
                    ]),
                    Grid::make(2)->schema([
                        TextInput::make('per_student_limit')
                            ->label('Per-student award limit')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue(1)
                            ->default(1),
                        TextInput::make('total_budget_minor')
                            ->label('Total campaign budget (optional)')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->helperText('Same smallest-currency-unit format as the credit amount above. Leave blank for no cap.'),
                    ]),
                ]),

            Section::make('Terms')
                ->columnSpanFull()
                ->schema([
                    Textarea::make('terms')
                        ->label('Terms and conditions')
                        ->rows(5)
                        ->maxLength(20000)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
