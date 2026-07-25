<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromotionalCreditCampaigns\RelationManagers;

use App\Support\MoneyFormatter;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Read-only issuance history for this campaign — no create/edit/delete. */
class IssuancesRelationManager extends RelationManager
{
    protected static string $relationship = 'issuances';

    protected static ?string $title = 'Issuances';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedBanknotes;

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('issued_at')->dateTime()->sortable(),
                TextColumn::make('student.name')->label('Student'),
                TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->state(fn ($record): string => MoneyFormatter::format($record->amount_minor, $record->currency_code)),
                TextColumn::make('issuedByUser.name')->label('Issued by'),
                TextColumn::make('reason')->wrap()->limit(100),
            ])
            ->headerActions([])
            ->recordActions([])
            ->bulkActions([])
            ->defaultSort('issued_at', 'desc');
    }
}
