<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromotionalCreditIssuances\Schemas;

use App\Models\PromotionalCreditIssuance;
use App\PromotionalCredits\Enums\PromotionalCreditIssuanceType;
use App\Support\MoneyFormatter;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PromotionalCreditIssuanceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Issuance')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('student.name')->label('Student'),
                        TextEntry::make('issuance_type')
                            ->badge()
                            ->formatStateUsing(fn (PromotionalCreditIssuanceType $state): string => $state->label()),
                        TextEntry::make('amount_minor')
                            ->label('Amount')
                            ->state(fn (PromotionalCreditIssuance $record): string => MoneyFormatter::format($record->amount_minor, $record->currency_code)),
                    ]),
                    Grid::make(2)->schema([
                        TextEntry::make('campaign.name')->label('Campaign')->placeholder('—'),
                        TextEntry::make('issuedByUser.name')->label('Issued by'),
                    ]),
                    TextEntry::make('reason')->columnSpanFull(),
                    Grid::make(2)->schema([
                        TextEntry::make('issued_at')->dateTime(),
                        TextEntry::make('idempotency_key')->label('Reference')->copyable(),
                    ]),
                ]),
        ]);
    }
}
