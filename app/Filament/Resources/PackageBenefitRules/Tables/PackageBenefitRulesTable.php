<?php

declare(strict_types=1);

namespace App\Filament\Resources\PackageBenefitRules\Tables;

use App\Filament\Support\Tables\AdminListTable;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class PackageBenefitRulesTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('name')
                    ->label('Offer Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('paid_quantity')
                    ->label('Paid Lessons')
                    ->sortable(),
                TextColumn::make('bonus_quantity')
                    ->label('Bonus Lessons')
                    ->sortable(),
                TextColumn::make('total_quantity')
                    ->label('Total Lessons')
                    ->sortable(),
                TextColumn::make('validity_days')
                    ->label('Validity')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? 'No expiry' : sprintf('%d days', $state))
                    ->sortable(),
                ToggleColumn::make('is_active')
                    ->label('Active')
                    ->disabled(fn ($record): bool => ! (auth()->user()?->can('update', $record) ?? false)),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->defaultSort('name');

        return AdminListTable::apply($table);
    }
}
