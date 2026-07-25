<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromotionalCreditCampaigns\Schemas;

use App\Models\PromotionalCreditCampaign;
use App\PromotionalCredits\Enums\PromotionalCreditCampaignStatus;
use App\PromotionalCredits\Services\PromotionalCreditService;
use App\Support\MoneyFormatter;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PromotionalCreditCampaignInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Campaign')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('name'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (PromotionalCreditCampaignStatus $state): string => $state->label())
                            ->color(fn (PromotionalCreditCampaignStatus $state): string => $state->color()),
                        TextEntry::make('amount_minor')
                            ->label('Credit amount')
                            ->state(fn (PromotionalCreditCampaign $record): string => MoneyFormatter::format($record->amount_minor, $record->currency_code)),
                    ]),
                    Grid::make(2)->schema([
                        TextEntry::make('starts_at')->label('Starts (UTC)')->dateTime(),
                        TextEntry::make('ends_at')->label('Ends (UTC)')->dateTime(),
                    ]),
                    TextEntry::make('description')->placeholder('—')->columnSpanFull(),
                ]),

            Section::make('Usage')
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('per_student_limit')->label('Per-student limit'),
                        TextEntry::make('usage.issued_count')
                            ->label('Awards issued')
                            ->state(fn (PromotionalCreditCampaign $record): int => app(PromotionalCreditService::class)->campaignUsageSummary($record)['issued_count']),
                        TextEntry::make('usage.issued_amount')
                            ->label('Amount issued')
                            ->state(fn (PromotionalCreditCampaign $record): string => MoneyFormatter::format(
                                app(PromotionalCreditService::class)->campaignUsageSummary($record)['issued_amount_minor'],
                                $record->currency_code,
                            )),
                        TextEntry::make('usage.budget_remaining')
                            ->label('Budget remaining')
                            ->state(function (PromotionalCreditCampaign $record): string {
                                $summary = app(PromotionalCreditService::class)->campaignUsageSummary($record);

                                return $summary['budget_remaining_minor'] !== null
                                    ? MoneyFormatter::format($summary['budget_remaining_minor'], $record->currency_code)
                                    : 'No cap';
                            }),
                    ]),
                ]),
        ]);
    }
}
