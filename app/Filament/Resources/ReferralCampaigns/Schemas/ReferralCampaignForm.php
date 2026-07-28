<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReferralCampaigns\Schemas;

use App\Models\Country;
use App\Models\Currency;
use App\Referral\Enums\ReferralRewardTiming;
use App\Referral\Enums\ReferralRewardType;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Campaign rule form. Status is deliberately absent — lifecycle moves
 * only through the table's service-backed actions. Percentage value is
 * entered directly as integer basis points (500 = 5%), never a float;
 * fixed value is integer minor units with a mandatory currency. All
 * datetimes are UTC.
 */
class ReferralCampaignForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Campaign')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('name')
                                ->required()
                                ->maxLength(255)
                                ->placeholder('e.g. Launch Referral 5%'),
                            Toggle::make('requires_fraud_review')
                                ->label('Hold rewards for fraud review')
                                ->inline(false)
                                ->helperText('Every reward from this campaign starts Held until an administrator approves it.'),
                        ]),
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
                                ->after('starts_at')
                                ->helperText('The campaign runs from the start time up to, but not including, the end time.'),
                        ]),
                    ]),

                Section::make('Reward rules')
                    ->description('Configure how the referral reward is calculated — as a percentage of the eligible lesson price (in basis points) or as a fixed amount.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('reward_type')
                                ->label('Reward type')
                                ->options(collect(ReferralRewardType::cases())
                                    ->mapWithKeys(fn (ReferralRewardType $t) => [$t->value => $t->label()])
                                    ->toArray())
                                ->required()
                                ->native(false)
                                ->live(),
                            TextInput::make('reward_value')
                                ->label(fn (Get $get): string => $get('reward_type') === ReferralRewardType::Fixed->value
                                    ? 'Reward amount (smallest currency unit)'
                                    : 'Reward (basis points)')
                                ->numeric()
                                ->integer()
                                ->required()
                                ->minValue(1)
                                ->maxValue(fn (Get $get): ?int => $get('reward_type') === ReferralRewardType::Percentage->value ? 10000 : null)
                                ->helperText(fn (Get $get): string => $get('reward_type') === ReferralRewardType::Fixed->value
                                    ? 'Enter the amount in the smallest unit of the selected currency — e.g. 10000 equals 100.00.'
                                    : '100 basis points = 1%. Example: 500 = 5% of each eligible lesson.'),
                            Select::make('reward_currency_code')
                                ->label('Fixed reward currency')
                                ->options(fn (): array => Currency::query()->active()->orderBy('sort_order')->pluck('code', 'code')->toArray())
                                ->native(false)
                                ->visible(fn (Get $get): bool => $get('reward_type') === ReferralRewardType::Fixed->value)
                                ->requiredIf('reward_type', ReferralRewardType::Fixed->value)
                                ->helperText('A future fixed reward credits only a matching referrer wallet currency — never converted.'),
                        ]),
                        Grid::make(2)->schema([
                            TextInput::make('min_completed_paid_lessons')
                                ->label('Minimum completed paid lessons')
                                ->numeric()
                                ->integer()
                                ->required()
                                ->minValue(1)
                                ->default(1),
                            TextInput::make('max_rewarded_classes')
                                ->label('Maximum rewarded classes per referred student')
                                ->numeric()
                                ->integer()
                                ->required()
                                ->minValue(1)
                                ->default(10),
                        ]),
                        Grid::make(2)->schema([
                            Select::make('reward_timing')
                                ->label('Reward timing')
                                ->options(collect(ReferralRewardTiming::cases())
                                    ->mapWithKeys(fn (ReferralRewardTiming $t) => [$t->value => $t->label()])
                                    ->toArray())
                                ->required()
                                ->native(false)
                                ->default(ReferralRewardTiming::Immediate->value)
                                ->live(),
                            TextInput::make('hold_days')
                                ->label('Hold days')
                                ->numeric()
                                ->integer()
                                ->minValue(1)
                                ->maxValue(365)
                                ->visible(fn (Get $get): bool => $get('reward_timing') === ReferralRewardTiming::AfterHoldDays->value)
                                ->requiredIf('reward_timing', ReferralRewardTiming::AfterHoldDays->value),
                        ]),
                    ]),

                Section::make('Eligibility & terms')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('eligible_countries')
                            ->label('Eligible countries')
                            ->multiple()
                            ->options(fn (): array => Country::query()->orderBy('name')->pluck('name', 'id')->toArray())
                            ->searchable()
                            ->preload()
                            ->helperText('Leave empty to apply the campaign in every country.')
                            ->columnSpanFull(),
                        Textarea::make('terms')
                            ->label('Terms and conditions')
                            ->rows(5)
                            ->maxLength(20000)
                            ->helperText('Shown to students on the referral page. Plain text; translated copies come with localization.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
